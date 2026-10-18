"""Modelos Pydantic de los eventos v1 que se intercambian con Laravel.

La fuente de verdad son los JSON Schema de `/contracts/events/` (los usa
también Laravel). Estos modelos los reflejan a mano y los tests de contrato
(`tests/contract/`) comprueban con los mismos ejemplos que ambos aceptan y
rechazan lo mismo.

Lector tolerante: campos desconocidos en el nivel superior se ignoran (un
productor puede añadir campos opcionales sin romper la v1). Dentro de
`classification` y de cada llamada al modelo no se admite nada extra: son
datos que produce este servicio.
"""

import uuid
from datetime import UTC, datetime
from typing import Annotated, Any, Literal, Self

from pydantic import (
    AwareDatetime,
    BaseModel,
    ConfigDict,
    Field,
    StrictBool,
    ValidationError,
    model_validator,
)

from ai_service.domain.category import Category

TenantId = Annotated[int, Field(ge=1)]
EmailId = Annotated[int, Field(ge=1)]
TraceId = Annotated[str, Field(pattern=r"^[A-Za-z0-9._-]{8,64}$")]
Probability = Annotated[float, Field(ge=0, le=1)]
TokenCount = Annotated[int, Field(ge=0)]
ThreadId = Annotated[str, Field(pattern=r"^[A-Za-z0-9._:-]{8,128}$")]
ToolName = Annotated[str, Field(pattern=r"^[a-z][a-z0-9_]{0,63}$")]
# live: el resultado se aplica al email; shadow: solo se compara (sin efectos).
Mode = Literal["live", "shadow"]
Specialist = Literal["orders", "billing", "support", "general"]
ToolOrigin = Literal["plan", "model", "approval"]


class InvalidEventError(ValueError):
    """El mensaje no cumple el contrato. Nunca se reintenta: va a la cola de errores."""


class _Event(BaseModel):
    model_config = ConfigDict(frozen=True, extra="ignore", populate_by_name=True)

    event_id: uuid.UUID = Field(default_factory=uuid.uuid4)
    event_version: Literal[1] = 1
    occurred_at: AwareDatetime = Field(default_factory=lambda: datetime.now(UTC))
    trace_id: TraceId
    tenant_id: TenantId
    email_id: EmailId

    def to_json_dict(self) -> dict[str, Any]:
        """Forma exacta del contrato (alias como `from`, fechas en RFC 3339)."""
        return self.model_dump(mode="json", by_alias=True)

    def to_json(self) -> str:
        return self.model_dump_json(by_alias=True)

    @classmethod
    def parse(cls, raw: str | bytes | dict[str, Any]) -> Self:
        try:
            if isinstance(raw, dict):
                return cls.model_validate(raw)
            return cls.model_validate_json(raw)
        except ValidationError as error:
            raise InvalidEventError(f"{cls.__name__} no cumple el contrato: {error}") from error


class EmailReceived(_Event):
    """`email.received.v1`: Laravel pide a la IA que procese un email."""

    event_type: Literal["email.received"] = "email.received"
    # Validación ligera a propósito (como `format: email` del esquema): Laravel ya
    # aceptó el correo. Un validador estricto rechazaría direcciones reales (o
    # los dominios .test de desarrollo) y el email se quedaría sin procesar.
    sender: str = Field(alias="from", max_length=320, pattern=r"^[^@\s]+@[^@\s]+$")
    subject: str = Field(max_length=998)
    body: str = Field(max_length=50_000)
    received_at: AwareDatetime
    from_name: str | None = Field(default=None, max_length=255)
    body_truncated: bool = False
    attachment_names: list[Annotated[str, Field(max_length=255)]] = Field(
        default_factory=list, max_length=50
    )
    tenant_name: str | None = Field(default=None, max_length=255)
    # Indicación de una persona del equipo al reprocesar desde el panel.
    review_instruction: str | None = Field(default=None, max_length=2000)
    # shadow: el motor de Laravel procesa el email; esto es solo para comparar (Fase 5).
    mode: Mode = "live"
    # Herramientas activadas para la empresa (las decide Laravel). None = el
    # evento no las trae: solo las activadas por defecto en el catálogo.
    enabled_tools: list[ToolName] | None = Field(default=None, max_length=50)

    @model_validator(mode="after")
    def _shadow_without_instruction(self) -> Self:
        if self.mode == "shadow" and self.review_instruction is not None:
            raise ValueError("una copia en modo sombra no lleva indicación de una persona")
        return self

    @model_validator(mode="after")
    def _unique_tools(self) -> Self:
        if self.enabled_tools is not None and len(set(self.enabled_tools)) != len(
            self.enabled_tools
        ):
            raise ValueError("enabled_tools no puede repetir herramientas")
        return self


class LLMCallUsage(BaseModel):
    """Una llamada al modelo; Laravel guarda una fila de `ai_requests` por cada una."""

    model_config = ConfigDict(frozen=True, extra="forbid")

    purpose: str = Field(pattern=r"^[a-z][a-z0-9_.]{1,63}$")
    provider: str = Field(min_length=1, max_length=50)
    requested_model: str = Field(min_length=1, max_length=100)
    model: str = Field(min_length=1, max_length=100)
    status: Literal["success", "failed", "refused"]
    input_tokens: TokenCount
    output_tokens: TokenCount
    cache_write_tokens: TokenCount = 0
    cache_read_tokens: TokenCount = 0
    latency_ms: Annotated[int, Field(ge=0)]
    stop_reason: str | None = Field(default=None, max_length=50)
    provider_request_id: str | None = Field(default=None, max_length=255)
    error: str | None = Field(default=None, max_length=500)
    prompt: str | None = Field(default=None, pattern=r"^[a-z][a-z0-9_]{1,63}@[0-9]{1,6}$")


class Usage(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid")

    calls: list[LLMCallUsage] = Field(default_factory=list, max_length=50)


class ClassificationPayload(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid")

    confidence: Probability
    summary: str = Field(max_length=2000)
    sentiment: Literal["positive", "neutral", "negative"]
    urgency: Literal["low", "normal", "high"]
    injection_suspected: bool
    needs_human: bool
    needs_human_reason: str | None = Field(max_length=500)
    order_numbers: list[Annotated[str, Field(max_length=100)]] = Field(max_length=20)
    invoice_numbers: list[Annotated[str, Field(max_length=100)]] = Field(max_length=20)
    customer_name: str | None = Field(max_length=255)


class SourcePayload(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid")

    type: Literal["document", "order", "invoice", "ticket", "customer"]
    reference: str = Field(min_length=1, max_length=255)


class ToolCallPayload(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid")

    tool: ToolName
    arguments: dict[str, Any]
    result: dict[str, Any] | None
    status: Literal["success", "error", "rejected"]
    error: str | None = Field(max_length=500)
    duration_ms: Annotated[int, Field(ge=0)]
    # Quién decidió la llamada (Fase 5). Eventos anteriores no lo traen: el plan.
    origin: ToolOrigin = "plan"


class PendingActionPayload(BaseModel):
    """Acción que modifica datos y espera la aprobación de una persona."""

    model_config = ConfigDict(frozen=True, extra="forbid")

    action_id: uuid.UUID
    tool: ToolName
    arguments: dict[str, Any]
    summary: str = Field(min_length=1, max_length=500)


class EmailProcessed(_Event):
    """`email.processed.v1`: resultado del grafo. `decision` es una recomendación."""

    event_type: Literal["email.processed"] = "email.processed"
    causation_id: uuid.UUID | None = None
    category: Category
    draft: str = Field(max_length=20_000)
    confidence: Probability
    decision: Literal["auto_send", "human_review", "escalate"]
    tools_used: list[Annotated[str, Field(pattern=r"^[a-z][a-z0-9_]{0,63}$")]] = Field(
        max_length=50
    )
    language: str | None = Field(max_length=20)
    reasons: list[Annotated[str, Field(max_length=500)]] = Field(max_length=20)
    model: str = Field(min_length=1, max_length=100)
    classification: ClassificationPayload
    sources: list[SourcePayload] = Field(default_factory=list, max_length=20)
    tool_calls: list[ToolCallPayload] = Field(default_factory=list, max_length=50)
    duration_ms: Annotated[int, Field(ge=0)] = 0
    usage: Usage
    # Grafo en pausa esperando la aprobación de `pending_action` (Fase 4).
    thread_id: ThreadId | None = None
    pending_action: PendingActionPayload | None = None
    mode: Mode = "live"
    # Especialistas que consultaron datos; el primero es el principal (Fase 5).
    specialists: list[Specialist] = Field(default_factory=list, max_length=4)

    @model_validator(mode="after")
    def _consistent(self) -> Self:
        if len(set(self.specialists)) != len(self.specialists):
            raise ValueError("especialistas repetidos")
        if self.mode == "shadow" and (self.pending_action is not None or self.thread_id):
            raise ValueError("en modo sombra no hay acciones pendientes ni pausas")
        if self.decision == "auto_send" and not self.draft:
            raise ValueError("auto_send exige un borrador")
        if self.pending_action is not None:
            if self.thread_id is None:
                raise ValueError("una acción pendiente exige thread_id")
            if self.decision == "auto_send":
                raise ValueError("con una acción pendiente no se envía nada solo")
        return self


ErrorCode = Literal["llm_config", "refusal", "invalid_output", "timeout", "internal", "expired"]


class EmailProcessingFailed(_Event):
    """`email.processing_failed.v1`: error definitivo (los temporales se reintentan aquí)."""

    event_type: Literal["email.processing_failed"] = "email.processing_failed"
    causation_id: uuid.UUID | None = None
    error_code: ErrorCode
    message: str = Field(min_length=1, max_length=500)
    retryable: bool
    usage: Usage
    mode: Mode = "live"


class EmailReviewResumed(_Event):
    """`email.review_resumed.v1`: una persona aprobó o rechazó la acción pendiente."""

    event_type: Literal["email.review_resumed"] = "email.review_resumed"
    thread_id: ThreadId
    action_id: uuid.UUID
    approved: StrictBool  # "yes" o 1 no valen: es la decisión de una persona
    reviewer_id: Annotated[int, Field(ge=1)] | None = None
