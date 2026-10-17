from dataclasses import replace

import pytest

from ai_service.application.contracts.events import (
    EmailProcessed,
    EmailProcessingFailed,
    EmailReceived,
)
from ai_service.application.dto import AgentOutcome, PendingAction, ToolCallRecord
from ai_service.application.errors import (
    AgentError,
    LLMConfigurationError,
    LLMRefusalError,
    LLMUnavailableError,
)
from ai_service.application.use_cases.process_email import ProcessEmail
from ai_service.domain.category import Category
from ai_service.domain.decision import Decision, DecisionOutcome
from ai_service.domain.draft import Source, SourceType
from ai_service.domain.email import Email
from ai_service.domain.specialist import Specialist
from tests.fakes import FakeAgent, InMemoryEventBus, make_call
from tests.support.contracts import assert_matches_contract, example
from tests.unit.domain.factories import make_classification, make_draft


def received() -> EmailReceived:
    return EmailReceived.parse(example("email.received.v1"))


def outcome(decision: Decision = Decision.AUTO_SEND, reasons: tuple[str, ...] = ()) -> AgentOutcome:
    return AgentOutcome(
        classification=make_classification(order_numbers=("45821",), customer_name="Juan Pérez"),
        draft=make_draft(),
        outcome=DecisionOutcome(decision, reasons),
        calls=(
            make_call("ai_service.classification", model="claude-opus-5-5"),
            make_call("ai_service.draft", model="claude-sonnet-5-5"),
        ),
    )


async def test_publishes_the_processed_event_with_everything_laravel_needs() -> None:
    bus = InMemoryEventBus()
    agent = FakeAgent(outcome=outcome())

    result = await ProcessEmail(agent, bus).execute(received())

    assert result.outcome == "processed"
    [event] = bus.published
    assert isinstance(event, EmailProcessed)
    assert (event.tenant_id, event.email_id, event.trace_id) == (1, 42, "9f2c4e6a8b1d4f3e")
    assert event.causation_id == received().event_id
    assert event.decision == "auto_send"
    assert event.category == "product_question"
    assert event.draft.startswith("Hola:")
    assert event.confidence == 0.93
    assert event.model == "claude-sonnet-5-5"  # el que redactó
    assert event.classification.order_numbers == ["45821"]
    assert [c.purpose for c in event.usage.calls] == [
        "ai_service.classification",
        "ai_service.draft",
    ]
    assert_matches_contract("email.processed.v1", event.to_json_dict())


async def test_the_agent_receives_the_email_of_the_event_tenant() -> None:
    agent = FakeAgent(outcome=outcome())

    await ProcessEmail(agent, InMemoryEventBus()).execute(received())

    [email] = agent.seen
    assert email.tenant_id.value == 1
    assert str(email.sender) == "cliente@cliente.test"
    assert email.tenant_name == "Empresa Demo"


async def test_reasons_and_review_decisions_are_forwarded() -> None:
    bus = InMemoryEventBus()
    agent = FakeAgent(
        outcome=outcome(Decision.ESCALATE, ("El cliente menciona acciones legales.",))
    )

    await ProcessEmail(agent, bus).execute(received())

    [event] = bus.published
    assert isinstance(event, EmailProcessed)
    assert event.decision == "escalate"
    assert event.reasons == ["El cliente menciona acciones legales."]


@pytest.mark.parametrize(
    ("error", "code"),
    [
        (LLMRefusalError("El modelo se negó."), "refusal"),
        (LLMConfigurationError("Revisa ANTHROPIC_API_KEY."), "llm_config"),
    ],
)
async def test_definitive_errors_publish_a_failure_with_billed_calls(
    error: LLMRefusalError | LLMConfigurationError, code: str
) -> None:
    bus = InMemoryEventBus()
    calls = (make_call(), make_call("ai_service.draft", status="refused", output_tokens=0))
    agent = FakeAgent(error=AgentError(error, calls))

    result = await ProcessEmail(agent, bus).execute(received())

    assert result.outcome == "failed"
    [event] = bus.published
    assert isinstance(event, EmailProcessingFailed)
    assert event.error_code == code
    assert event.message == error.message
    assert not event.retryable
    assert len(event.usage.calls) == 2
    assert_matches_contract("email.processing_failed.v1", event.to_json_dict())


async def test_temporary_errors_are_raised_for_a_retry_and_nothing_is_published() -> None:
    bus = InMemoryEventBus()
    agent = FakeAgent(error=AgentError(LLMUnavailableError("529 sobrecargado"), ()))

    with pytest.raises(AgentError):
        await ProcessEmail(agent, bus).execute(received())

    assert bus.published == []


async def test_publishing_errors_propagate_so_the_message_is_not_acknowledged() -> None:
    bus = InMemoryEventBus(fail_with=ConnectionError("redis caído"))

    with pytest.raises(ConnectionError):
        await ProcessEmail(FakeAgent(outcome=outcome()), bus).execute(received())


async def test_spam_without_draft_is_published_with_empty_draft() -> None:
    bus = InMemoryEventBus()
    spam = AgentOutcome(
        classification=make_classification(category=Category.SPAM),
        draft=None,
        outcome=DecisionOutcome(Decision.HUMAN_REVIEW, ("No necesita respuesta (spam).",)),
        calls=(make_call(),),
    )

    await ProcessEmail(FakeAgent(outcome=spam), bus).execute(received())

    [event] = bus.published
    assert isinstance(event, EmailProcessed)
    assert (event.draft, event.confidence, event.decision) == ("", 0.0, "human_review")
    assert event.language == "es"
    assert_matches_contract("email.processed.v1", event.to_json_dict())


async def test_sources_tool_calls_and_duration_reach_the_event() -> None:
    bus = InMemoryEventBus()
    source = Source(SourceType.DOCUMENT, "Política de envíos, sección «Coste del envío»")
    search = ToolCallRecord(
        tool="search_company_documents",
        arguments={"query": "envíos a Canarias"},
        result={"results": [{"source": source.reference, "content": "14,95 €"}]},
        status="success",
        duration_ms=180,
    )
    result = AgentOutcome(
        classification=make_classification(),
        draft=make_draft(sources=(source,)),
        outcome=DecisionOutcome(Decision.AUTO_SEND),
        calls=(make_call(),),
        tool_calls=(search, replace(search, tool="get_order", origin="model")),
        duration_ms=4200,
        specialists=(Specialist.GENERAL, Specialist.ORDERS),
    )

    await ProcessEmail(FakeAgent(outcome=result), bus).execute(received())

    [event] = bus.published
    assert isinstance(event, EmailProcessed)
    assert [(s.type, s.reference) for s in event.sources] == [("document", source.reference)]
    assert event.tool_calls[0].tool == "search_company_documents"
    assert event.tools_used == ["search_company_documents", "get_order"]
    assert [t.origin for t in event.tool_calls] == ["plan", "model"]
    assert event.specialists == ["general", "orders"]
    assert event.duration_ms == 4200
    assert_matches_contract("email.processed.v1", event.to_json_dict())


async def test_a_paused_run_publishes_the_pending_action_and_its_thread() -> None:
    action = PendingAction(
        action_id="c3d4e5f6-a7b8-4c9d-8e0f-1a2b3c4d5e6f",
        tool="create_support_ticket",
        arguments={"subject": "La lámpara no enciende", "description": "x", "priority": "normal"},
        summary="Crear un ticket de soporte «La lámpara no enciende» (prioridad normal).",
    )
    paused = AgentOutcome(
        classification=make_classification(category=Category.TECHNICAL_SUPPORT),
        draft=None,
        outcome=DecisionOutcome(Decision.HUMAN_REVIEW, ("La IA propone una acción…",)),
        calls=(make_call(),),
        pending_action=action,
        thread_id="t1-e42-0b8f6c1e-3a52-4c1f-9a77-2f1d5e4b9c10",
    )
    bus = InMemoryEventBus()
    agent = FakeAgent(outcome=paused)

    await ProcessEmail(agent, bus).execute(received())

    [event] = bus.published
    assert isinstance(event, EmailProcessed)
    assert event.thread_id == "t1-e42-0b8f6c1e-3a52-4c1f-9a77-2f1d5e4b9c10"
    assert event.pending_action is not None
    assert str(event.pending_action.action_id) == action.action_id
    assert (event.draft, event.decision) == ("", "human_review")
    assert_matches_contract("email.processed.v1", event.to_json_dict())


async def test_each_request_runs_in_its_own_thread() -> None:
    seen: list[str | None] = []

    class RecordingAgent(FakeAgent):
        async def run(
            self, email: Email, *, thread_id: str | None = None, shadow: bool = False
        ) -> AgentOutcome:
            seen.append(thread_id)
            return await super().run(email, thread_id=thread_id, shadow=shadow)

    await ProcessEmail(RecordingAgent(outcome=outcome()), InMemoryEventBus()).execute(received())

    event = received()
    assert seen == [f"t{event.tenant_id}-e{event.email_id}-{event.event_id}"]


async def test_the_team_instruction_reaches_the_agent() -> None:
    event = EmailReceived.parse(example("email.received.v1--with-review-instruction"))
    agent = FakeAgent(outcome=outcome())

    await ProcessEmail(agent, InMemoryEventBus()).execute(event)

    assert agent.seen[0].review_instruction == (
        "Ofrécele el cambio por otra talla; no hables de reembolso."
    )


async def test_a_shadow_copy_runs_without_actions_and_is_published_as_shadow() -> None:
    bus = InMemoryEventBus()
    agent = FakeAgent(outcome=outcome())
    shadow = EmailReceived.parse(example("email.received.v1--shadow"))

    await ProcessEmail(agent, bus).execute(shadow)

    assert agent.shadow_runs == [True]
    [event] = bus.published
    assert isinstance(event, EmailProcessed)
    assert event.mode == "shadow"
    assert event.thread_id is None
    assert event.pending_action is None
    assert_matches_contract("email.processed.v1", event.to_json_dict())


async def test_a_failed_shadow_copy_is_published_as_shadow() -> None:
    bus = InMemoryEventBus()
    error = AgentError(LLMRefusalError("No.", (make_call(status="refused"),)), ())
    shadow = EmailReceived.parse(example("email.received.v1--shadow"))

    await ProcessEmail(FakeAgent(error=error), bus).execute(shadow)

    [event] = bus.published
    assert isinstance(event, EmailProcessingFailed)
    assert event.mode == "shadow"
    assert_matches_contract("email.processing_failed.v1", event.to_json_dict())


async def test_live_emails_are_not_shadow() -> None:
    bus = InMemoryEventBus()
    agent = FakeAgent(outcome=outcome())

    await ProcessEmail(agent, bus).execute(received())

    assert agent.shadow_runs == [False]
    assert bus.published[0].mode == "live"
