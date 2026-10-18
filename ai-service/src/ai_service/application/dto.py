"""Datos que cruzan los puertos (sin dependencias de ningún proveedor)."""

from dataclasses import dataclass, field
from typing import Any, Literal

from ai_service.domain.classification import Classification
from ai_service.domain.decision import DecisionOutcome
from ai_service.domain.draft import Draft
from ai_service.domain.email import EmailAddress, TenantId
from ai_service.domain.knowledge import KnowledgeChunk
from ai_service.domain.specialist import Specialist

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
    # Quién decidió la llamada: el plan del backend, el modelo o una persona.
    origin: Literal["plan", "model", "approval"] = "plan"


@dataclass(frozen=True, slots=True)
class ClassificationResult:
    classification: Classification
    # Normalmente una; con fallback de servidor, una por modelo que intervino.
    calls: tuple[LLMCall, ...]


@dataclass(frozen=True, slots=True)
class ToolSpec:
    """Herramienta que se le ofrece al modelo al redactar: nombre, para qué sirve y
    sus argumentos (JSON Schema). Nunca incluye la empresa ni el cliente."""

    name: str
    description: str
    input_schema: dict[str, Any]


@dataclass(frozen=True, slots=True)
class ToolRequest:
    """Consulta que pide el modelo (`call_id` = el id que le dio su proveedor)."""

    call_id: str
    tool: str
    arguments: dict[str, Any]


@dataclass(frozen=True, slots=True)
class ToolAnswer:
    """Resultado de una consulta pedida por el modelo, para devolvérselo."""

    request: ToolRequest
    record: "ToolCallRecord"


@dataclass(frozen=True, slots=True)
class DraftConversation:
    """Conversación de redacción en curso (cuando el modelo pide consultas).

    `turns` es opaco para el grafo: lo construye y lo interpreta el adaptador
    del modelo (p. ej. los mensajes de langchain, con los bloques de
    razonamiento intactos: el historial solo se puede ampliar, nunca editar).
    """

    turns: tuple[Any, ...]


@dataclass(frozen=True, slots=True)
class DraftResult:
    """Un paso de redacción: el borrador, o las consultas que pide el modelo
    antes de redactarlo (con la conversación para continuar)."""

    draft: Draft | None
    calls: tuple[LLMCall, ...]
    tool_requests: tuple[ToolRequest, ...] = ()
    conversation: DraftConversation | None = None


@dataclass(frozen=True, slots=True)
class PendingAction:
    """Acción que modifica datos de la empresa y espera la aprobación de una
    persona. Los argumentos los construye el backend (nunca el modelo); empresa
    y cliente se añaden al ejecutarla. `action_id` es también la clave de
    idempotencia: ejecutarla dos veces no crea dos tickets."""

    action_id: str
    tool: str
    arguments: dict[str, Any]
    summary: str


@dataclass(frozen=True, slots=True)
class AgentOutcome:
    """Lo que devuelve el agente al terminar el grafo (o al quedar en pausa)."""

    classification: Classification
    draft: Draft | None
    outcome: DecisionOutcome
    # Solo las llamadas al modelo de ESTA ejecución (las de antes de una pausa ya
    # se informaron a Laravel: se facturarían dos veces).
    calls: tuple[LLMCall, ...]
    tool_calls: tuple[ToolCallRecord, ...] = field(default_factory=tuple)
    duration_ms: int = 0
    # Grafo en pausa esperando la aprobación de esta acción.
    pending_action: PendingAction | None = None
    thread_id: str | None = None
    # Especialistas que intervinieron; el primero es el principal (Fase 5).
    specialists: tuple[Specialist, ...] = ()

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
class EmbeddingBatch:
    """Vectores de varios textos (en su orden) y las llamadas que costó."""

    vectors: tuple[tuple[float, ...], ...]
    calls: tuple[LLMCall, ...]


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
    # Especialistas que intervinieron (el primero es el principal): sus reglas
    # de redacción aplican.
    specialists: tuple[Specialist, ...] = ()


@dataclass(frozen=True, slots=True)
class ToolContext:
    """Para quién se llama a una herramienta. Lo construye el backend con el email
    que se procesa (empresa del evento y remitente); NUNCA sale del modelo."""

    tenant_id: TenantId
    customer: EmailAddress
