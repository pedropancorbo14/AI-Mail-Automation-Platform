"""Clasificación de un email: qué quiere el cliente y cómo de seguro está el modelo."""

from dataclasses import dataclass, field
from enum import StrEnum

from ai_service.domain.category import Category
from ai_service.domain.confidence import Confidence


class Sentiment(StrEnum):
    POSITIVE = "positive"
    NEUTRAL = "neutral"
    NEGATIVE = "negative"


class Urgency(StrEnum):
    LOW = "low"
    NORMAL = "normal"
    HIGH = "high"


@dataclass(frozen=True, slots=True)
class Classification:
    category: Category
    confidence: Confidence
    language: str
    summary: str
    sentiment: Sentiment
    urgency: Urgency
    injection_suspected: bool
    needs_human: bool
    needs_human_reason: str | None = None
    order_numbers: tuple[str, ...] = field(default_factory=tuple)
    invoice_numbers: tuple[str, ...] = field(default_factory=tuple)
    customer_name: str | None = None

    def __post_init__(self) -> None:
        # Regla de negocio: con sospecha de manipulación siempre decide una persona,
        # diga lo que diga el modelo (mismo criterio que el clasificador de Laravel).
        if self.injection_suspected and not self.needs_human:
            object.__setattr__(self, "needs_human", True)
            object.__setattr__(
                self,
                "needs_human_reason",
                self.needs_human_reason or "Posible intento de manipular a la IA.",
            )
