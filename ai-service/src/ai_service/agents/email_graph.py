"""Grafo de LangGraph que decide qué hacer con un email.

    START → classify ─router─┬─→ retrieve ─→ tools ─→ draft ─→ validate ─┬─→ END   (auto_send)
                             └──────────────────────────────────→ validate ┴─→ human_review → END

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
- **draft**: borrador (`LLMPort.draft`) con la documentación encontrada.
- **validate**: reglas puras del dominio (`DecisionPolicy`), con lo que se
  consultó de verdad (`Evidence`: una cita a algo no consultado → persona).
- **human_review**: nodo final que deja constancia de que lo revisará una
  persona; la revisión se hace en el panel de Laravel (Fase 13). El
  `interrupt` + checkpointing llega en la Fase 4.

El grafo solo conoce puertos (`LLMPort`) y el dominio: import-linter impide
que importe adaptadores.
"""

import asyncio
import operator
import time
from collections.abc import Awaitable
from typing import Annotated, Any, Literal, NotRequired, Protocol, TypedDict

from langgraph.graph import END, START, StateGraph
from langgraph.graph.state import CompiledStateGraph

from ai_service.application.dto import (
    AgentOutcome,
    DraftContext,
    LLMCall,
    ToolCallRecord,
    ToolContext,
)
from ai_service.application.errors import AgentError, LLMError, LLMUnavailableError
from ai_service.application.ports import LLMPort, ToolPort
from ai_service.application.services.knowledge import KnowledgeRetriever
from ai_service.application.services.tool_planner import plan_tool_calls, references_in
from ai_service.domain.classification import Classification
from ai_service.domain.decision import Decision, DecisionOutcome
from ai_service.domain.draft import Draft
from ai_service.domain.email import Email
from ai_service.domain.knowledge import KnowledgeChunk
from ai_service.domain.policy import DecisionPolicy, Evidence

Route = Literal["retrieve", "validate"]
SEARCH_TOOL = "search_company_documents"


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
        max_tool_calls: int = 5,
        timeout_seconds: float = 240.0,
    ) -> None:
        self._llm = llm
        self._retriever = retriever
        self._tools = tools
        self._max_tool_calls = max_tool_calls
        self._policy = policy
        self._timeout = timeout_seconds
        self.graph = self._build()

    def _build(self) -> CompiledStateGraph[EmailState, None, EmailState, EmailState]:
        graph = StateGraph(EmailState)
        graph.add_node("classify", _billed(self._classify))
        graph.add_node("retrieve", self._retrieve)
        graph.add_node("tools", self._call_tools)
        graph.add_node("draft", _billed(self._draft))
        graph.add_node("validate", self._validate)
        graph.add_node("human_review", self._human_review)

        graph.add_edge(START, "classify")
        graph.add_conditional_edges(
            "classify", router, {"retrieve": "retrieve", "validate": "validate"}
        )
        graph.add_edge("retrieve", "tools")
        graph.add_edge("tools", "draft")
        graph.add_edge("draft", "validate")
        graph.add_conditional_edges(
            "validate", self._after_validate, {"end": END, "human_review": "human_review"}
        )
        graph.add_edge("human_review", END)
        return graph.compile()

    async def run(self, email: Email) -> AgentOutcome:
        initial: EmailState = {
            "email": email,
            "calls": [],
            "tool_calls": [],
            "issues": [],
            "visited": [],
        }
        started = time.perf_counter()
        try:
            async with asyncio.timeout(self._timeout):
                # El grafo no tiene ciclos: 10 pasos sobran; protege de errores futuros.
                state = await self.graph.ainvoke(initial, config={"recursion_limit": 10})
        except TimeoutError as error:
            cause = LLMUnavailableError(f"El agente superó {self._timeout:.0f} s.")
            raise AgentError(cause, ()) from error

        return AgentOutcome(
            classification=state["classification"],
            draft=state.get("draft"),
            outcome=state["outcome"],
            calls=tuple(state["calls"]),
            tool_calls=tuple(state["tool_calls"]),
            duration_ms=int((time.perf_counter() - started) * 1000),
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

    async def _draft(self, state: EmailState) -> dict[str, Any]:
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
