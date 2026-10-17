"""Dobles de prueba de los puertos (implementan los Protocol sin heredar)."""

from collections.abc import Callable, Sequence
from dataclasses import dataclass, field

from ai_service.application.dto import (
    AgentOutcome,
    ClassificationResult,
    DraftContext,
    DraftResult,
    EmbeddingResult,
    LLMCall,
)
from ai_service.application.errors import AgentError, KnowledgeBaseError, LLMError
from ai_service.application.ports import OutgoingEvent
from ai_service.domain.classification import Classification
from ai_service.domain.email import Email, TenantId
from ai_service.domain.knowledge import KnowledgeChunk


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
DraftStep = DraftResult | LLMError | Callable[[Email, Classification, DraftContext], DraftResult]


@dataclass
class FakeLLM:
    """Modelo con respuestas guionizadas: nunca llama a ninguna API."""

    classifications: list[ClassifyStep] = field(default_factory=list)
    drafts: list[DraftStep] = field(default_factory=list)
    classify_calls: list[Email] = field(default_factory=list)
    draft_calls: list[tuple[Email, Classification, DraftContext]] = field(default_factory=list)

    async def classify(self, email: Email) -> ClassificationResult:
        self.classify_calls.append(email)
        step = self.classifications.pop(0)
        if isinstance(step, LLMError):
            raise step
        return step if isinstance(step, ClassificationResult) else step(email)

    async def draft(
        self, email: Email, classification: Classification, context: DraftContext
    ) -> DraftResult:
        self.draft_calls.append((email, classification, context))
        step = self.drafts.pop(0)
        if isinstance(step, LLMError):
            raise step
        return step if isinstance(step, DraftResult) else step(email, classification, context)


def make_chunk(source_title: str = "Política de envíos", **overrides: object) -> KnowledgeChunk:
    values: dict[str, object] = {
        "chunk_id": 7,
        "document_id": 2,
        "title": source_title,
        "content": "Canarias: 14,95 €, de 5 a 7 días laborables.",
        "similarity": 0.74,
        "metadata": {"heading": "Coste del envío"},
    }
    return KnowledgeChunk(**(values | overrides))  # type: ignore[arg-type]


@dataclass
class FakeEmbeddings:
    model: str = "bge-m3"
    error: LLMError | None = None
    queries: list[str] = field(default_factory=list)

    async def embed_query(self, text: str) -> EmbeddingResult:
        self.queries.append(text)
        if self.error:
            raise self.error
        call = make_call("ai_service.knowledge_search", provider="ollama", model=self.model)
        return EmbeddingResult((1.0, 0.0), call)


@dataclass
class FakeVectorStore:
    """Documentación por empresa en memoria; registra con qué empresa se buscó."""

    by_tenant: dict[int, list[KnowledgeChunk]] = field(default_factory=dict)
    error: KnowledgeBaseError | None = None
    searches: list[TenantId] = field(default_factory=list)

    async def search(
        self,
        tenant_id: TenantId,
        vector: Sequence[float],
        *,
        embedding_model: str,
        limit: int,
        min_similarity: float,
    ) -> list[KnowledgeChunk]:
        self.searches.append(tenant_id)
        if self.error:
            raise self.error
        return self.by_tenant.get(tenant_id.value, [])[:limit]
