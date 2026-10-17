"""Dobles de prueba de los puertos (implementan los Protocol sin heredar)."""

from collections.abc import Callable
from dataclasses import dataclass, field

from ai_service.application.dto import (
    AgentOutcome,
    ClassificationResult,
    DraftResult,
    LLMCall,
)
from ai_service.application.errors import AgentError, LLMError
from ai_service.application.ports import OutgoingEvent
from ai_service.domain.classification import Classification
from ai_service.domain.email import Email


def make_call(purpose: str = "ai_service.classify", **overrides: object) -> LLMCall:
    values: dict[str, object] = {
        "purpose": purpose,
        "provider": "fake",
        "requested_model": "fake-model",
        "model": "fake-model",
        "status": "success",
        "input_tokens": 100,
        "output_tokens": 20,
        "latency_ms": 5,
        "stop_reason": "end_turn",
    }
    return LLMCall(**(values | overrides))  # type: ignore[arg-type]


@dataclass
class InMemoryEventBus:
    published: list[OutgoingEvent] = field(default_factory=list)
    fail_with: Exception | None = None

    async def publish(self, event: OutgoingEvent) -> None:
        if self.fail_with:
            raise self.fail_with
        self.published.append(event)


@dataclass
class FakeAgent:
    outcome: AgentOutcome | None = None
    error: AgentError | None = None
    seen: list[Email] = field(default_factory=list)

    async def run(self, email: Email) -> AgentOutcome:
        self.seen.append(email)
        if self.error:
            raise self.error
        assert self.outcome is not None
        return self.outcome


ClassifyStep = ClassificationResult | LLMError | Callable[[Email], ClassificationResult]
DraftStep = DraftResult | LLMError | Callable[[Email, Classification], DraftResult]


@dataclass
class FakeLLM:
    """Modelo con respuestas guionizadas: nunca llama a ninguna API."""

    classifications: list[ClassifyStep] = field(default_factory=list)
    drafts: list[DraftStep] = field(default_factory=list)
    classify_calls: list[Email] = field(default_factory=list)
    draft_calls: list[tuple[Email, Classification]] = field(default_factory=list)

    async def classify(self, email: Email) -> ClassificationResult:
        self.classify_calls.append(email)
        step = self.classifications.pop(0)
        if isinstance(step, LLMError):
            raise step
        return step if isinstance(step, ClassificationResult) else step(email)

    async def draft(self, email: Email, classification: Classification) -> DraftResult:
        self.draft_calls.append((email, classification))
        step = self.drafts.pop(0)
        if isinstance(step, LLMError):
            raise step
        return step if isinstance(step, DraftResult) else step(email, classification)
