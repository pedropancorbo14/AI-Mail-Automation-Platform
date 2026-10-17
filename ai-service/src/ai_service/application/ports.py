"""Puertos: lo que la aplicación necesita del exterior, en su propio lenguaje.

Los implementan los adaptadores de `infrastructure/` (Anthropic, Redis
Streams) y el grafo de `agents/`. Se usan `Protocol` (tipado estructural):
un adaptador no hereda de nada de este paquete, y un fake de test tampoco.
"""

from collections.abc import Mapping, Sequence
from typing import Any, Protocol

from ai_service.application.contracts.events import (
    EmailProcessed,
    EmailProcessingFailed,
)
from ai_service.application.dto import (
    AgentOutcome,
    ClassificationResult,
    DraftContext,
    DraftConversation,
    DraftResult,
    EmbeddingResult,
    PendingAction,
    ToolAnswer,
    ToolCallRecord,
    ToolContext,
    ToolSpec,
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
        self,
        email: Email,
        classification: Classification,
        context: DraftContext,
        *,
        tools: Sequence[ToolSpec] = (),
        conversation: DraftConversation | None = None,
        answers: Sequence[ToolAnswer] = (),
    ) -> DraftResult:
        """Redacta el borrador. Con `tools`, el modelo puede pedir antes consultas
        (`DraftResult.tool_requests`); se le devuelven en la siguiente llamada con
        la misma `conversation` y sus `answers`. Las herramientas ofrecidas no
        cambian durante la conversación."""
        ...


class EmailAgentPort(Protocol):
    """El agente que decide qué hacer con un email (el grafo de LangGraph).

    Errores: `AgentError` con todas las llamadas facturadas.
    """

    async def run(self, email: Email, *, thread_id: str | None = None) -> AgentOutcome:
        """`thread_id` identifica la ejecución por si queda en pausa (acción
        pendiente de aprobación)."""
        ...

    async def resume(
        self, thread_id: str, *, tenant_id: TenantId, email_id: int, action_id: str, approved: bool
    ) -> AgentOutcome:
        """Reanuda una ejecución en pausa con la decisión de una persona.

        `ResumeExpiredError` (en un `AgentError`) si ya no existe;
        `ThreadMismatchError` si no es de esa empresa, ese email o esa acción.
        """
        ...


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


class ToolPort(Protocol):
    """Herramientas de los sistemas de la empresa (CRM, ERP, tickets) vía MCP.

    `context` (empresa y cliente) lo pone el backend; `arguments` son solo los
    propios de la herramienta. Nunca lanza: los fallos vuelven como
    `ToolCallRecord` con `status` error o rejected (el modelo los lee y el caso va
    a una persona).
    """

    @property
    def available(self) -> frozenset[str]: ...

    async def call(
        self, tool: str, arguments: Mapping[str, Any], context: ToolContext
    ) -> ToolCallRecord: ...


class ActionPort(Protocol):
    """Acciones que MODIFICAN datos de la empresa (crear un ticket...). Solo se
    ejecutan después de que una persona las apruebe. Nunca lanza."""

    @property
    def available_actions(self) -> frozenset[str]: ...

    async def execute(self, action: PendingAction, context: ToolContext) -> ToolCallRecord: ...
