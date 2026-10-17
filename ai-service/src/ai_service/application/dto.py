"""Datos que cruzan los puertos (sin dependencias de ningún proveedor)."""

from dataclasses import dataclass, field
from typing import Any, Literal

from ai_service.domain.classification import Classification
from ai_service.domain.decision import DecisionOutcome
from ai_service.domain.draft import Draft
from ai_service.domain.email import EmailAddress, TenantId
from ai_service.domain.knowledge import KnowledgeChunk

CallStatus = Literal["success", "failed", "refused"]


@dataclass(frozen=True, slots=True)
class LLMCall:
    """Una llamada al modelo, con lo que se factura. Laravel la guarda en ai_requests."""

    purpose: str
    provider: str
    requested_model: str
    model: str
    status: CallStatus
    input_tokens: int = 0
    output_tokens: int = 0
    cache_write_tokens: int = 0
    cache_read_tokens: int = 0
    latency_ms: int = 0
    stop_reason: str | None = None
    provider_request_id: str | None = None
    error: str | None = None
    prompt: str | None = None  # clave@versión del prompt usado


@dataclass(frozen=True, slots=True)
class ToolCallRecord:
    """Una herramienta usada (la búsqueda en la documentación incluida). Laravel la
    guarda en ai_tool_calls; es lo que su revisión del texto acepta como datos."""

    tool: str
    arguments: dict[str, Any]
    result: dict[str, Any] | None
    status: Literal["success", "error", "rejected"]
    error: str | None = None
    duration_ms: int = 0


@dataclass(frozen=True, slots=True)
class ClassificationResult:
    classification: Classification
    # Normalmente una; con fallback de servidor, una por modelo que intervino.
    calls: tuple[LLMCall, ...]


@dataclass(frozen=True, slots=True)
class DraftResult:
    draft: Draft
    calls: tuple[LLMCall, ...]


@dataclass(frozen=True, slots=True)
class AgentOutcome:
    """Lo que devuelve el agente al terminar el grafo."""

    classification: Classification
    draft: Draft | None
    outcome: DecisionOutcome
    calls: tuple[LLMCall, ...]
    tool_calls: tuple[ToolCallRecord, ...] = field(default_factory=tuple)
    duration_ms: int = 0

    @property
    def tools_used(self) -> tuple[str, ...]:
        return tuple(dict.fromkeys(t.tool for t in self.tool_calls if t.status == "success"))

    @property
    def model(self) -> str:
        """Modelo que redactó la respuesta (o el que clasificó si no hubo borrador)."""
        successful = [c for c in self.calls if c.status == "success"]
        return successful[-1].model if successful else "unknown"


@dataclass(frozen=True, slots=True)
class EmbeddingResult:
    vector: tuple[float, ...]
    call: LLMCall


@dataclass(frozen=True, slots=True)
class RetrievalResult:
    """Lo que encontró el RAG para un email (y lo que costó buscarlo)."""

    chunks: tuple[KnowledgeChunk, ...]
    calls: tuple[LLMCall, ...] = ()
    failed: str | None = None  # motivo si no se pudo consultar (el email sigue, a revisión)


@dataclass(frozen=True, slots=True)
class DraftContext:
    """Lo que el modelo tiene delante al redactar, además del email."""

    knowledge: tuple[KnowledgeChunk, ...] = ()
    # Hubo documentación que consultar pero no se pudo (Ollama o la BD caídos).
    knowledge_unavailable: bool = False
    # Datos del cliente de los sistemas de la empresa (MCP), con su estado.
    tool_results: tuple["ToolCallRecord", ...] = ()


@dataclass(frozen=True, slots=True)
class ToolContext:
    """Para quién se llama a una herramienta. Lo construye el backend con el email
    que se procesa (empresa del evento y remitente); NUNCA sale del modelo."""

    tenant_id: TenantId
    customer: EmailAddress
