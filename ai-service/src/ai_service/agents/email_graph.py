"""Grafo de LangGraph que decide qué hacer con un email.

    START → classify ─router─┬─→ retrieve → tools → propose_action ─┬────────────────┬→ draft
                             │                                      └→ await_approval ┘
                             └──────────────────────────────────────────────────→ validate
    draft → validate ─┬─→ END            (auto_send)
                      └─→ human_review → END

- **classify**: clasificación con structured outputs (`LLMPort.classify`).
- **router**: arista condicional **determinista** (sin IA): sospecha de
  manipulación o categoría que no se contesta (spam, respuesta automática)
  → directo a validate, sin gastar en redactar; el resto → retrieve.
- **retrieve**: documentación de la empresa (RAG, `KnowledgeRetriever`),
  siempre de la empresa del email. Se registra como la herramienta
  `search_company_documents`. Si falla, el email sigue y va a revisión.
- **tools**: datos del cliente en los sistemas de la empresa (CRM, ERP,
  tickets) por MCP, planificados de forma determinista según la intención
  (`plan_tool_calls`). Empresa y cliente los pone el backend. Cualquier
  consulta que falle o no encuentre lo pedido manda el caso a una persona.
- **propose_action**: acción que modifica datos (crear un ticket) planificada
  de forma determinista (`plan_action`). Solo con checkpoints configurados:
  hace falta poder esperar a la persona.
- **await_approval**: `interrupt` de LangGraph. El grafo queda **en pausa**
  (checkpoint en PostgreSQL) y el servicio publica el resultado con la acción
  pendiente; cuando una persona decide, `resume()` lo reanuda aquí: si la
  aprobó se ejecuta (idempotente, por MCP) y se redacta con su resultado; si
  no, se redacta sin ella. Va en un nodo aparte de propose_action porque al
  reanudar LangGraph repite el nodo del `interrupt` entero: así la acción no se
  vuelve a planificar (tendría otro `action_id`).
- **draft**: borrador (`LLMPort.draft`) con la documentación encontrada. Si
  el email ya gastó su presupuesto de tokens, no se redacta (a una persona).
- **validate**: reglas puras del dominio (`DecisionPolicy`), con lo que se
  consultó de verdad (`Evidence`: una cita a algo no consultado → persona).
- **human_review**: nodo final que deja constancia de que lo revisará una
  persona; la revisión se hace en el panel de Laravel (Fase 13). No pausa el
  grafo: reprocesar con una indicación vuelve a empezar con datos frescos.

El grafo solo conoce puertos (`LLMPort`) y el dominio: import-linter impide
que importe adaptadores.
"""

import asyncio
import logging
import operator
import time
import uuid
from collections.abc import Awaitable
from typing import Annotated, Any, Literal, NotRequired, Protocol, TypedDict

from langchain_core.runnables import RunnableConfig
from langgraph.checkpoint.base import BaseCheckpointSaver
from langgraph.errors import GraphRecursionError
from langgraph.graph import END, START, StateGraph
from langgraph.graph.state import CompiledStateGraph
from langgraph.types import Command, interrupt

from ai_service.application.dto import (
    AgentOutcome,
    DraftContext,
    LLMCall,
    PendingAction,
    ToolCallRecord,
    ToolContext,
)
from ai_service.application.errors import (
    AgentError,
    AgentLimitError,
    LLMError,
    LLMUnavailableError,
    ResumeExpiredError,
    ThreadMismatchError,
)
from ai_service.application.ports import ActionPort, LLMPort, ToolPort
from ai_service.application.services.action_planner import plan_action
from ai_service.application.services.knowledge import KnowledgeRetriever
from ai_service.application.services.tool_planner import plan_tool_calls, references_in
from ai_service.domain.classification import Classification
from ai_service.domain.decision import Decision, DecisionOutcome
from ai_service.domain.draft import Draft
from ai_service.domain.email import Email, TenantId
from ai_service.domain.knowledge import KnowledgeChunk
from ai_service.domain.policy import DecisionPolicy, Evidence

Route = Literal["retrieve", "validate"]
SEARCH_TOOL = "search_company_documents"
# Las búsquedas de embeddings son locales (Ollama): no cuentan para el presupuesto.
FREE_PURPOSES = frozenset({"ai_service.knowledge_search"})

log = logging.getLogger(__name__)


def tokens_used(calls: list[LLMCall] | tuple[LLMCall, ...]) -> int:
    """Tokens facturados del email (entrada + salida, también de llamadas fallidas)."""
    return sum(c.input_tokens + c.output_tokens for c in calls if c.purpose not in FREE_PURPOSES)


class EmailState(TypedDict):
    email: Email
    # Reductor "sumar": cada nodo añade sus llamadas al modelo (se facturan todas).
    calls: Annotated[list[LLMCall], operator.add]
    classification: NotRequired[Classification]
    draft: NotRequired[Draft | None]
    outcome: NotRequired[DecisionOutcome]
    route: NotRequired[Route]
    knowledge: NotRequired[tuple[KnowledgeChunk, ...]]
    knowledge_unavailable: NotRequired[bool]
    tool_calls: Annotated[list[ToolCallRecord], operator.add]
    issues: Annotated[list[str], operator.add]
    visited: Annotated[list[str], operator.add]
    pending_action: NotRequired[PendingAction | None]


class Node(Protocol):
    # Parámetro con nombre `state`: es lo que espera el tipado de LangGraph.
    def __call__(self, state: EmailState) -> Awaitable[dict[str, Any]]: ...


def _billed(node: Node) -> Node:
    """Si el modelo falla en un nodo, `AgentError` lleva TODAS las llamadas hechas
    hasta entonces (también las de nodos anteriores): se facturaron igual."""

    async def wrapper(state: EmailState) -> dict[str, Any]:
        try:
            return await node(state)
        except LLMError as error:
            raise AgentError(error, (*state["calls"], *error.calls)) from error

    return wrapper


def router(state: EmailState) -> Route:
    """Determinista: decide si merece la pena redactar."""
    classification = state["classification"]
    if classification.injection_suspected or not classification.category.needs_reply:
        return "validate"
    return "retrieve"


class LangGraphEmailAgent:
    """Implementa `EmailAgentPort` con un grafo de LangGraph."""

    def __init__(
        self,
        llm: LLMPort,
        policy: DecisionPolicy,
        *,
        retriever: KnowledgeRetriever | None = None,
        tools: ToolPort | None = None,
        actions: ActionPort | None = None,
        checkpointer: BaseCheckpointSaver[Any] | None = None,
        max_tool_calls: int = 5,
        max_tokens: int | None = None,
        max_steps: int = 15,
        timeout_seconds: float = 240.0,
    ) -> None:
        self._llm = llm
        self._retriever = retriever
        self._tools = tools
        self._actions = actions
        self._checkpointer = checkpointer
        self._max_tool_calls = max_tool_calls
        self._max_tokens = max_tokens
        self._max_steps = max_steps
        self._policy = policy
        self._timeout = timeout_seconds
        self.graph = self._build()

    def _build(self) -> CompiledStateGraph[EmailState, None, EmailState, EmailState]:
        graph = StateGraph(EmailState)
        graph.add_node("classify", _billed(self._classify))
        graph.add_node("retrieve", self._retrieve)
        graph.add_node("tools", self._call_tools)
        graph.add_node("propose_action", self._propose_action)
        graph.add_node("await_approval", self._await_approval)
        graph.add_node("draft", _billed(self._draft))
        graph.add_node("validate", self._validate)
        graph.add_node("human_review", self._human_review)

        graph.add_edge(START, "classify")
        graph.add_conditional_edges(
            "classify", router, {"retrieve": "retrieve", "validate": "validate"}
        )
        graph.add_edge("retrieve", "tools")
        graph.add_edge("tools", "propose_action")
        graph.add_conditional_edges(
            "propose_action",
            self._after_proposal,
            {"await_approval": "await_approval", "draft": "draft"},
        )
        graph.add_edge("await_approval", "draft")
        graph.add_edge("draft", "validate")
        graph.add_conditional_edges(
            "validate", self._after_validate, {"end": END, "human_review": "human_review"}
        )
        graph.add_edge("human_review", END)
        return graph.compile(checkpointer=self._checkpointer)

    @property
    def can_wait_for_approval(self) -> bool:
        return self._checkpointer is not None and self._actions is not None

    async def run(self, email: Email, *, thread_id: str | None = None) -> AgentOutcome:
        initial: EmailState = {
            "email": email,
            "calls": [],
            "tool_calls": [],
            "issues": [],
            "visited": [],
        }
        thread_id = thread_id or f"t{email.tenant_id.value}-e{email.email_id}-{uuid.uuid4()}"
        return await self._invoke(initial, thread_id, reported_calls=0)

    async def resume(
        self,
        thread_id: str,
        *,
        tenant_id: TenantId,
        email_id: int,
        action_id: str,
        approved: bool,
    ) -> AgentOutcome:
        if self._checkpointer is None:
            raise AgentError(ResumeExpiredError("No hay ejecuciones en pausa que reanudar."), ())
        snapshot = await self.graph.aget_state(self._config(thread_id))
        values: dict[str, Any] = snapshot.values or {}
        if not values or not snapshot.next:
            expired = ResumeExpiredError(
                "La acción pendiente ya no se puede reanudar (caducó o ya se resolvió). "
                "Reprocesa el email."
            )
            raise AgentError(expired, ())
        email: Email = values["email"]
        pending: PendingAction | None = values.get("pending_action")
        if email.tenant_id != tenant_id or email.email_id != email_id:
            raise ThreadMismatchError("La reanudación no es de la empresa o el email de la pausa.")
        if pending is None or pending.action_id != action_id:
            raise ThreadMismatchError("La reanudación no es de la acción pendiente.")
        decision: Command[Any] = Command(resume={"action_id": action_id, "approved": approved})
        return await self._invoke(decision, thread_id, reported_calls=len(values["calls"]))

    def _config(self, thread_id: str) -> RunnableConfig:
        # El grafo no tiene ciclos: el límite de pasos protege de errores futuros.
        return {"recursion_limit": self._max_steps, "configurable": {"thread_id": thread_id}}

    async def _invoke(
        self, payload: EmailState | Command[Any], thread_id: str, *, reported_calls: int
    ) -> AgentOutcome:
        started = time.perf_counter()
        try:
            async with asyncio.timeout(self._timeout):
                if self._checkpointer is None:
                    state = await self.graph.ainvoke(
                        payload, config={"recursion_limit": self._max_steps}
                    )
                else:
                    # durability="exit": se guarda al pausar o terminar, no en cada nodo.
                    state = await self.graph.ainvoke(
                        payload, config=self._config(thread_id), durability="exit"
                    )
        except TimeoutError as error:
            timeout = LLMUnavailableError(f"El agente superó {self._timeout:.0f} s.")
            raise AgentError(timeout, ()) from error
        except GraphRecursionError as error:
            log.warning("agent_limit_reached", extra={"limit": "steps"})
            limit = AgentLimitError(f"El agente superó el límite de {self._max_steps} pasos.")
            raise AgentError(limit, ()) from error

        duration_ms = int((time.perf_counter() - started) * 1000)
        calls = tuple(state["calls"][reported_calls:])
        if "__interrupt__" in state:
            pending: PendingAction = state["pending_action"]
            reason = (
                "La IA propone una acción que necesita la aprobación de una persona: "
                + pending.summary
            )
            return AgentOutcome(
                classification=state["classification"],
                draft=None,
                outcome=DecisionOutcome(Decision.HUMAN_REVIEW, (reason,)),
                calls=calls,
                tool_calls=tuple(state["tool_calls"]),
                duration_ms=duration_ms,
                pending_action=pending,
                thread_id=thread_id,
            )
        if self._checkpointer is not None:
            # Terminado: su estado (con el email) no se guarda.
            await self._checkpointer.adelete_thread(thread_id)
        return AgentOutcome(
            classification=state["classification"],
            draft=state.get("draft"),
            outcome=state["outcome"],
            calls=calls,
            tool_calls=tuple(state["tool_calls"]),
            duration_ms=duration_ms,
        )

    async def _classify(self, state: EmailState) -> dict[str, Any]:
        result = await self._llm.classify(state["email"])
        return {
            "classification": result.classification,
            "calls": list(result.calls),
            "visited": ["classify"],
        }

    async def _retrieve(self, state: EmailState) -> dict[str, Any]:
        if self._retriever is None:  # sin RAG configurado: se redacta sin documentación
            return {"visited": ["retrieve"]}
        email = state["email"]
        started = time.perf_counter()
        result = await self._retriever.retrieve(email)
        record = ToolCallRecord(
            tool=SEARCH_TOOL,
            arguments={"query": KnowledgeRetriever.query_for(email)[:500]},
            result=None
            if result.failed
            else {
                "results": [
                    {
                        "source": c.source,
                        "document_id": c.document_id,
                        "chunk_id": c.chunk_id,
                        "similarity": c.similarity,
                        "content": c.content[:1500],
                    }
                    for c in result.chunks
                ]
            },
            status="error" if result.failed else "success",
            error=result.failed,
            duration_ms=int((time.perf_counter() - started) * 1000),
        )
        issues = (
            [f"No se pudo consultar la documentación: {result.failed}"] if result.failed else []
        )
        return {
            "knowledge": result.chunks,
            "knowledge_unavailable": bool(result.failed),
            "calls": list(result.calls),
            "tool_calls": [record],
            "issues": issues,
            "visited": ["retrieve"],
        }

    async def _call_tools(self, state: EmailState) -> dict[str, Any]:
        if self._tools is None:  # sin servidor MCP configurado
            return {"visited": ["tools"]}
        email = state["email"]
        plan = plan_tool_calls(state["classification"], self._tools.available)
        plan = plan[: self._max_tool_calls]
        context = ToolContext(tenant_id=email.tenant_id, customer=email.sender)
        records = await asyncio.gather(
            *(self._tools.call(p.tool, p.arguments, context) for p in plan)
        )
        issues = [
            f"La consulta {r.tool} no fue bien: {r.error or r.status}"
            for r in records
            if r.status != "success"
        ]
        return {"tool_calls": list(records), "issues": issues, "visited": ["tools"]}

    async def _propose_action(self, state: EmailState) -> dict[str, Any]:
        if not self.can_wait_for_approval or self._actions is None:
            return {"visited": ["propose_action"]}
        action = plan_action(
            state["email"],
            state["classification"],
            [r for r in state["tool_calls"] if r.tool != SEARCH_TOOL],
            self._actions.available_actions,
        )
        return {"pending_action": action, "visited": ["propose_action"]}

    @staticmethod
    def _after_proposal(state: EmailState) -> Literal["await_approval", "draft"]:
        return "await_approval" if state.get("pending_action") else "draft"

    async def _await_approval(self, state: EmailState) -> dict[str, Any]:
        action = state.get("pending_action")
        if action is None or self._actions is None:  # no ocurre: lo garantiza la arista
            return {"visited": ["await_approval"]}
        # Pausa: el grafo se guarda y aquí vuelve, al reanudar, con la decisión.
        decision: dict[str, Any] = interrupt(
            {"action_id": action.action_id, "summary": action.summary}
        )
        approved = bool(decision.get("approved")) and decision.get("action_id") == action.action_id
        if not approved:
            rejected = ToolCallRecord(
                action.tool, dict(action.arguments), None, "rejected", "Una persona la rechazó."
            )
            return {"tool_calls": [rejected], "visited": ["await_approval"]}
        email = state["email"]
        record = await self._actions.execute(
            action, ToolContext(tenant_id=email.tenant_id, customer=email.sender)
        )
        issues = (
            []
            if record.status == "success"
            else [f"La acción aprobada {action.tool} no se pudo hacer: {record.error or 'error'}"]
        )
        return {"tool_calls": [record], "issues": issues, "visited": ["await_approval"]}

    async def _draft(self, state: EmailState) -> dict[str, Any]:
        used = tokens_used(state["calls"])
        if self._max_tokens is not None and used >= self._max_tokens:
            log.warning("agent_limit_reached", extra={"limit": "tokens", "used": used})
            issue = (
                f"El email alcanzó su límite de tokens ({used} de {self._max_tokens}): "
                "no se redactó la respuesta."
            )
            return {"draft": None, "issues": [issue], "visited": ["draft"]}
        context = DraftContext(
            knowledge=state.get("knowledge", ()),
            knowledge_unavailable=state.get("knowledge_unavailable", False),
            tool_results=tuple(r for r in state["tool_calls"] if r.tool != SEARCH_TOOL),
        )
        result = await self._llm.draft(state["email"], state["classification"], context)
        return {"draft": result.draft, "calls": list(result.calls), "visited": ["draft"]}

    async def _validate(self, state: EmailState) -> dict[str, Any]:
        references = {c.source for c in state.get("knowledge", ())}
        for record in state["tool_calls"]:
            if record.status == "success" and record.tool != SEARCH_TOOL:
                references |= references_in(record.tool, record.result)
        evidence = Evidence(references=frozenset(references), issues=tuple(state["issues"]))
        outcome = self._policy.decide(
            state["email"], state["classification"], state.get("draft"), evidence
        )
        return {"outcome": outcome, "visited": ["validate"]}

    @staticmethod
    def _after_validate(state: EmailState) -> Literal["end", "human_review"]:
        return "end" if state["outcome"].decision is Decision.AUTO_SEND else "human_review"

    async def _human_review(self, state: EmailState) -> dict[str, Any]:
        # Nodo final: Laravel lo lleva a la bandeja de revisión (Fase 13).
        return {"visited": ["human_review"]}
