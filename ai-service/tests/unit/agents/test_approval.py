"""Acciones con aprobación humana: el grafo queda en pausa (interrupt +
checkpoint) y se reanuda con la decisión de una persona. Checkpoints en
memoria (InMemorySaver); con PostgreSQL real en tests/integration."""

from typing import Any

import pytest
from langgraph.checkpoint.memory import InMemorySaver

from ai_service.agents.email_graph import LangGraphEmailAgent
from ai_service.application.dto import ClassificationResult, DraftResult
from ai_service.application.errors import AgentError, LLMUnavailableError, ThreadMismatchError
from ai_service.domain.category import Category
from ai_service.domain.decision import Decision
from ai_service.domain.draft import Source, SourceType
from ai_service.domain.email import TenantId
from ai_service.domain.policy import DecisionPolicy
from tests.fakes import FakeActionPort, FakeLLM, FakeToolPort, make_call
from tests.unit.domain.factories import make_classification, make_draft, make_email

THREAD = "t1-e42-event"
CLOSED_ONLY = {"tickets": [{"id": "TCK-0981", "status": "closed"}]}


def support() -> ClassificationResult:
    classification = make_classification(
        category=Category.TECHNICAL_SUPPORT, summary="La lámpara no enciende."
    )
    return ClassificationResult(classification, (make_call(),))


def drafted(**overrides: Any) -> DraftResult:
    return DraftResult(make_draft(**overrides), (make_call("ai_service.draft"),))


def agent(
    llm: FakeLLM,
    actions: FakeActionPort | None = None,
    tickets: dict[str, Any] | str = CLOSED_ONLY,
    checkpointer: InMemorySaver | None = None,
) -> LangGraphEmailAgent:
    return LangGraphEmailAgent(
        llm,
        DecisionPolicy(),
        tools=FakeToolPort(results={"get_customer_tickets": tickets}),
        actions=actions if actions is not None else FakeActionPort(),
        checkpointer=checkpointer if checkpointer is not None else InMemorySaver(),
        timeout_seconds=5,
    )


async def paused(llm: FakeLLM, **kwargs: Any) -> tuple[LangGraphEmailAgent, Any]:
    graph = agent(llm, **kwargs)
    return graph, await graph.run(make_email(subject="La lámpara no enciende"), thread_id=THREAD)


async def test_support_without_ticket_pauses_with_the_proposed_action() -> None:
    llm = FakeLLM(classifications=[support()], drafts=[drafted()])

    _, result = await paused(llm)

    assert result.thread_id == THREAD
    assert result.pending_action.tool == "create_support_ticket"
    assert result.pending_action.arguments["subject"] == "La lámpara no enciende"
    assert result.draft is None
    assert llm.draft_calls == []  # se redacta después, con el número de ticket
    assert result.outcome.decision is Decision.HUMAN_REVIEW
    assert result.outcome.reasons == (
        "La IA propone una acción que necesita la aprobación de una persona: "
        + result.pending_action.summary,
    )
    assert [c.purpose for c in result.calls] == ["ai_service.classify"]
    assert [t.tool for t in result.tool_calls] == ["get_customer_tickets"]


async def test_an_approved_action_runs_once_and_the_reply_cites_the_ticket() -> None:
    cited = (Source(SourceType.TICKET, "TCK-2001"),)
    llm = FakeLLM(classifications=[support()], drafts=[drafted(sources=cited)])
    actions = FakeActionPort()
    graph, first = await paused(llm, actions=actions)

    result = await graph.resume(
        THREAD,
        tenant_id=TenantId(1),
        email_id=42,
        action_id=first.pending_action.action_id,
        approved=True,
    )

    [(action, context)] = actions.executed
    assert action == first.pending_action  # la misma acción (y action_id) que se aprobó
    assert (context.tenant_id, context.customer.value) == (TenantId(1), "cliente@cliente.test")
    _, _, draft_context = llm.draft_calls[0]
    created = draft_context.tool_results[-1].result
    assert created is not None
    assert created["ticket"]["id"] == "TCK-2001"
    assert result.outcome.decision is Decision.AUTO_SEND
    assert result.pending_action is None
    # Solo lo nuevo: la clasificación ya se informó en la pausa (no se factura dos veces).
    assert [c.purpose for c in result.calls] == ["ai_service.draft"]
    # La evidencia completa (para la revisión del texto en Laravel).
    assert [t.tool for t in result.tool_calls] == ["get_customer_tickets", "create_support_ticket"]


async def test_a_rejected_action_is_not_run_and_is_recorded() -> None:
    llm = FakeLLM(classifications=[support()], drafts=[drafted()])
    actions = FakeActionPort()
    graph, first = await paused(llm, actions=actions)

    result = await graph.resume(
        THREAD,
        tenant_id=TenantId(1),
        email_id=42,
        action_id=first.pending_action.action_id,
        approved=False,
    )

    assert actions.executed == []
    rejected = result.tool_calls[-1]
    assert (rejected.tool, rejected.status, rejected.error) == (
        "create_support_ticket",
        "rejected",
        "Una persona la rechazó.",
    )
    assert len(llm.draft_calls) == 1


async def test_a_failed_action_sends_the_case_to_a_person() -> None:
    llm = FakeLLM(classifications=[support()], drafts=[drafted()])
    graph, first = await paused(llm, actions=FakeActionPort(fail_with="helpdesk caído"))

    result = await graph.resume(
        THREAD,
        tenant_id=TenantId(1),
        email_id=42,
        action_id=first.pending_action.action_id,
        approved=True,
    )

    assert result.outcome.decision is Decision.HUMAN_REVIEW
    assert (
        "La acción aprobada create_support_ticket no se pudo hacer: helpdesk caído"
        in result.outcome.reasons
    )


async def test_resuming_twice_finds_nothing_to_resume() -> None:
    llm = FakeLLM(classifications=[support()], drafts=[drafted()])
    graph, first = await paused(llm)
    decision = {
        "tenant_id": TenantId(1),
        "email_id": 42,
        "action_id": first.pending_action.action_id,
        "approved": True,
    }
    await graph.resume(THREAD, **decision)

    with pytest.raises(AgentError) as caught:
        await graph.resume(THREAD, **decision)  # entrega repetida de Redis

    assert caught.value.code == "expired"
    assert not caught.value.retryable


@pytest.mark.parametrize(
    ("tenant_id", "email_id", "same_action"),
    [(2, 42, True), (1, 43, True), (1, 42, False)],
    ids=["other-tenant", "other-email", "other-action"],
)
async def test_a_resume_for_something_else_is_refused(
    tenant_id: int, email_id: int, same_action: bool
) -> None:
    llm = FakeLLM(classifications=[support()], drafts=[drafted()])
    actions = FakeActionPort()
    graph, first = await paused(llm, actions=actions)
    action_id = first.pending_action.action_id if same_action else "otra-accion"

    with pytest.raises(ThreadMismatchError):
        await graph.resume(
            THREAD,
            tenant_id=TenantId(tenant_id),
            email_id=email_id,
            action_id=action_id,
            approved=True,
        )

    assert actions.executed == []
    # La pausa sigue ahí para la reanudación buena.
    result = await graph.resume(
        THREAD,
        tenant_id=TenantId(1),
        email_id=42,
        action_id=first.pending_action.action_id,
        approved=True,
    )
    assert len(actions.executed) == 1
    assert result.pending_action is None


async def test_unknown_threads_are_expired() -> None:
    graph = agent(FakeLLM())

    with pytest.raises(AgentError) as caught:
        await graph.resume(
            "t1-e42-desconocido", tenant_id=TenantId(1), email_id=42, action_id="x", approved=True
        )

    assert caught.value.code == "expired"


async def test_no_action_when_the_customer_already_has_an_open_ticket() -> None:
    llm = FakeLLM(classifications=[support()], drafts=[drafted()])

    _, result = await paused(llm, tickets={"tickets": [{"id": "TCK-1042", "status": "open"}]})

    assert result.pending_action is None
    assert len(llm.draft_calls) == 1


async def test_without_checkpoints_no_action_is_proposed() -> None:
    llm = FakeLLM(classifications=[support()], drafts=[drafted()])
    graph = LangGraphEmailAgent(
        llm,
        DecisionPolicy(),
        tools=FakeToolPort(results={"get_customer_tickets": CLOSED_ONLY}),
        actions=FakeActionPort(),
    )

    result = await graph.run(make_email())

    assert result.pending_action is None
    assert len(llm.draft_calls) == 1


async def test_finished_runs_leave_no_state_behind() -> None:
    saver = InMemorySaver()
    llm = FakeLLM(classifications=[support()], drafts=[drafted()])
    graph = agent(
        llm, tickets={"tickets": [{"id": "TCK-1042", "status": "open"}]}, checkpointer=saver
    )

    await graph.run(make_email(), thread_id=THREAD)

    assert await saver.aget_tuple({"configurable": {"thread_id": THREAD}}) is None


async def test_a_temporary_failure_after_approval_can_be_retried() -> None:
    """Si el modelo falla al redactar tras aprobar, la reanudación se reintenta:
    la pausa sigue y la acción se repite con el mismo action_id (idempotente)."""
    llm = FakeLLM(
        classifications=[support()],
        drafts=[LLMUnavailableError("529 sobrecargado"), drafted()],
    )
    actions = FakeActionPort()
    graph, first = await paused(llm, actions=actions)
    assert first.pending_action is not None
    decision = {
        "tenant_id": TenantId(1),
        "email_id": 42,
        "action_id": first.pending_action.action_id,
        "approved": True,
    }

    with pytest.raises(AgentError) as caught:
        await graph.resume(THREAD, **decision)
    assert caught.value.retryable

    result = await graph.resume(THREAD, **decision)

    assert result.draft is not None
    assert {a.action_id for a, _ in actions.executed} == {first.pending_action.action_id}
