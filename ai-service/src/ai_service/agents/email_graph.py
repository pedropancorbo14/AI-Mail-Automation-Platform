"""Grafo de LangGraph que decide qué hacer con un email.

    START → classify ─router─┬─→ draft ─→ validate ─┬─→ END            (auto_send)
                             └────────────→ validate ┴─→ human_review → END

- **classify**: clasificación con structured outputs (`LLMPort.classify`).
- **router**: arista condicional **determinista** (sin IA): sospecha de
  manipulación o categoría que no se contesta (spam, respuesta automática)
  → directo a validate, sin gastar en redactar. En las Fases 2 y 3 enviará
  a retrieve (RAG) y tools según la categoría.
- **draft**: borrador (`LLMPort.draft`).
- **validate**: reglas puras del dominio (`DecisionPolicy`).
- **human_review**: nodo final que deja constancia de que lo revisará una
  persona; la revisión se hace en el panel de Laravel (Fase 13). El
  `interrupt` + checkpointing llega en la Fase 4.

El grafo solo conoce puertos (`LLMPort`) y el dominio: import-linter impide
que importe adaptadores.
"""

import asyncio
import operator
from collections.abc import Awaitable
from typing import Annotated, Any, Literal, NotRequired, Protocol, TypedDict

from langgraph.graph import END, START, StateGraph
from langgraph.graph.state import CompiledStateGraph

from ai_service.application.dto import AgentOutcome, LLMCall
from ai_service.application.errors import AgentError, LLMError, LLMUnavailableError
from ai_service.application.ports import LLMPort
from ai_service.domain.classification import Classification
from ai_service.domain.decision import Decision, DecisionOutcome
from ai_service.domain.draft import Draft
from ai_service.domain.email import Email
from ai_service.domain.policy import DecisionPolicy

Route = Literal["draft", "validate"]


class EmailState(TypedDict):
    email: Email
    # Reductor "sumar": cada nodo añade sus llamadas al modelo (se facturan todas).
    calls: Annotated[list[LLMCall], operator.add]
    classification: NotRequired[Classification]
    draft: NotRequired[Draft | None]
    outcome: NotRequired[DecisionOutcome]
    route: NotRequired[Route]
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
    return "draft"


class LangGraphEmailAgent:
    """Implementa `EmailAgentPort` con un grafo de LangGraph."""

    def __init__(
        self, llm: LLMPort, policy: DecisionPolicy, *, timeout_seconds: float = 240.0
    ) -> None:
        self._llm = llm
        self._policy = policy
        self._timeout = timeout_seconds
        self.graph = self._build()

    def _build(self) -> CompiledStateGraph[EmailState, None, EmailState, EmailState]:
        graph = StateGraph(EmailState)
        graph.add_node("classify", _billed(self._classify))
        graph.add_node("draft", _billed(self._draft))
        graph.add_node("validate", self._validate)
        graph.add_node("human_review", self._human_review)

        graph.add_edge(START, "classify")
        graph.add_conditional_edges("classify", router, {"draft": "draft", "validate": "validate"})
        graph.add_edge("draft", "validate")
        graph.add_conditional_edges(
            "validate", self._after_validate, {"end": END, "human_review": "human_review"}
        )
        graph.add_edge("human_review", END)
        return graph.compile()

    async def run(self, email: Email) -> AgentOutcome:
        initial: EmailState = {"email": email, "calls": [], "visited": []}
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
            tools_used=(),
        )

    async def _classify(self, state: EmailState) -> dict[str, Any]:
        result = await self._llm.classify(state["email"])
        return {
            "classification": result.classification,
            "calls": list(result.calls),
            "visited": ["classify"],
        }

    async def _draft(self, state: EmailState) -> dict[str, Any]:
        result = await self._llm.draft(state["email"], state["classification"])
        return {"draft": result.draft, "calls": list(result.calls), "visited": ["draft"]}

    async def _validate(self, state: EmailState) -> dict[str, Any]:
        outcome = self._policy.decide(state["email"], state["classification"], state.get("draft"))
        return {"outcome": outcome, "visited": ["validate"]}

    @staticmethod
    def _after_validate(state: EmailState) -> Literal["end", "human_review"]:
        return "end" if state["outcome"].decision is Decision.AUTO_SEND else "human_review"

    async def _human_review(self, state: EmailState) -> dict[str, Any]:
        # Nodo final: Laravel lo lleva a la bandeja de revisión (Fase 13).
        return {"visited": ["human_review"]}
