"""Puertos: lo que la aplicación necesita del exterior, en su propio lenguaje.

Los implementan los adaptadores de `infrastructure/` (Anthropic, Redis
Streams) y el grafo de `agents/`. Se usan `Protocol` (tipado estructural):
un adaptador no hereda de nada de este paquete, y un fake de test tampoco.
"""

from collections.abc import Sequence
from typing import Protocol

from ai_service.application.contracts.events import (
    EmailProcessed,
    EmailProcessingFailed,
)
from ai_service.application.dto import (
    AgentOutcome,
    ClassificationResult,
    DraftContext,
    DraftResult,
    EmbeddingResult,
)
from ai_service.domain.classification import Classification
from ai_service.domain.email import Email, TenantId
from ai_service.domain.knowledge import KnowledgeChunk

OutgoingEvent = EmailProcessed | EmailProcessingFailed


class LLMPort(Protocol):
    """El modelo de lenguaje, expresado en operaciones del dominio.

    Los prompts y el formato de salida (structured outputs) son detalle del
    adaptador. Errores: subclases de `LLMError`.
    """

    async def classify(self, email: Email) -> ClassificationResult: ...

    async def draft(
        self, email: Email, classification: Classification, context: DraftContext
    ) -> DraftResult: ...


class EmailAgentPort(Protocol):
    """El agente que decide qué hacer con un email (el grafo de LangGraph).

    Errores: `AgentError` con todas las llamadas facturadas.
    """

    async def run(self, email: Email) -> AgentOutcome: ...


class EventBusPort(Protocol):
    """Publica eventos hacia Laravel."""

    async def publish(self, event: OutgoingEvent) -> None: ...


class EmbeddingPort(Protocol):
    """Convierte una pregunta en un vector, con el MISMO modelo que indexó los
    documentos en Laravel (si no, la búsqueda no encuentra lo correcto)."""

    @property
    def model(self) -> str: ...

    async def embed_query(self, text: str) -> EmbeddingResult: ...


class VectorStorePort(Protocol):
    """Búsqueda por significado en la documentación de UNA empresa.

    `tenant_id` es obligatorio: no existe una búsqueda "de todas las empresas".
    Errores: `KnowledgeBaseError`.
    """

    async def search(
        self,
        tenant_id: TenantId,
        vector: Sequence[float],
        *,
        embedding_model: str,
        limit: int,
        min_similarity: float,
    ) -> list[KnowledgeChunk]: ...
