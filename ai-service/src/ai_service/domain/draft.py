"""Borrador de respuesta propuesto por la IA."""

from dataclasses import dataclass, field
from enum import StrEnum

from ai_service.domain.confidence import Confidence


class SourceType(StrEnum):
    DOCUMENT = "document"
    ORDER = "order"
    INVOICE = "invoice"
    TICKET = "ticket"
    CUSTOMER = "customer"
    APPOINTMENT = "appointment"


@dataclass(frozen=True, slots=True)
class Source:
    """Dato que la respuesta cita (para que una persona lo compruebe)."""

    type: SourceType
    reference: str


@dataclass(frozen=True, slots=True)
class Draft:
    body: str
    language: str
    confidence: Confidence
    needs_human: bool = False
    needs_human_reason: str | None = None
    # Acciones que solo puede hacer una persona (reembolsar, cancelar...): la IA
    # solo informa, nunca las ejecuta ni las promete.
    pending_actions: tuple[str, ...] = field(default_factory=tuple)
    sources: tuple[Source, ...] = field(default_factory=tuple)

    def __post_init__(self) -> None:
        object.__setattr__(self, "body", self.body.strip())

    @property
    def is_empty(self) -> bool:
        return not self.body
