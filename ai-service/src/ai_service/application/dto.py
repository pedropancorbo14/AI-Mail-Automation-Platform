"""Datos que cruzan los puertos (sin dependencias de ningún proveedor)."""

from dataclasses import dataclass, field
from typing import Literal

from ai_service.domain.classification import Classification
from ai_service.domain.decision import DecisionOutcome
from ai_service.domain.draft import Draft

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
    tools_used: tuple[str, ...] = field(default_factory=tuple)

    @property
    def model(self) -> str:
        """Modelo que redactó la respuesta (o el que clasificó si no hubo borrador)."""
        successful = [c for c in self.calls if c.status == "success"]
        return successful[-1].model if successful else "unknown"
