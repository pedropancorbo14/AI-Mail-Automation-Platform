import pytest

from ai_service.application.contracts.events import (
    EmailProcessed,
    EmailProcessingFailed,
    EmailReviewResumed,
)
from ai_service.application.dto import AgentOutcome
from ai_service.application.errors import (
    AgentError,
    LLMUnavailableError,
    ResumeExpiredError,
    ThreadMismatchError,
)
from ai_service.application.use_cases.resume_review import ResumeReview
from ai_service.domain.email import TenantId
from tests.fakes import FakeAgent, InMemoryEventBus
from tests.support.contracts import assert_matches_contract, example
from tests.unit.application.test_process_email import outcome


def resumed() -> EmailReviewResumed:
    return EmailReviewResumed.parse(example("email.review_resumed.v1"))


async def test_resumes_the_paused_run_with_the_decision_and_publishes_the_result() -> None:
    bus = InMemoryEventBus()
    agent = FakeAgent(outcome=outcome())

    result = await ResumeReview(agent, bus).execute(resumed())

    assert agent.resumes == [
        {
            "thread_id": "t1-e42-0b8f6c1e-3a52-4c1f-9a77-2f1d5e4b9c10",
            "tenant_id": TenantId(1),
            "email_id": 42,
            "action_id": "c3d4e5f6-a7b8-4c9d-8e0f-1a2b3c4d5e6f",
            "approved": True,
        }
    ]
    assert result.outcome == "processed"
    [event] = bus.published
    assert isinstance(event, EmailProcessed)
    assert event.causation_id == resumed().event_id  # Laravel lo enlaza con su reanudación
    assert event.trace_id == resumed().trace_id
    assert_matches_contract("email.processed.v1", event.to_json_dict())


async def test_an_expired_pause_publishes_an_expired_failure() -> None:
    bus = InMemoryEventBus()
    expired = AgentError(ResumeExpiredError("La acción pendiente ya no se puede reanudar."), ())

    result = await ResumeReview(FakeAgent(error=expired), bus).execute(resumed())

    assert result.outcome == "failed"
    [event] = bus.published
    assert isinstance(event, EmailProcessingFailed)
    assert (event.error_code, event.retryable) == ("expired", False)
    assert event.causation_id == resumed().event_id
    assert_matches_contract("email.processing_failed.v1", event.to_json_dict())


async def test_temporary_errors_are_retried_without_publishing() -> None:
    bus = InMemoryEventBus()
    agent = FakeAgent(error=AgentError(LLMUnavailableError("529"), ()))

    with pytest.raises(AgentError):
        await ResumeReview(agent, bus).execute(resumed())

    assert bus.published == []


async def test_a_resume_for_another_tenant_email_or_action_is_not_swallowed() -> None:
    class Mismatch(FakeAgent):
        async def resume(
            self,
            thread_id: str,
            *,
            tenant_id: TenantId,
            email_id: int,
            action_id: str,
            approved: bool,
        ) -> AgentOutcome:
            raise ThreadMismatchError("otra empresa")

    with pytest.raises(ThreadMismatchError):
        await ResumeReview(Mismatch(), InMemoryEventBus()).execute(resumed())
