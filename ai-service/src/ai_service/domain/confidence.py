"""Confianza: un número entre 0 y 1."""

import math
from dataclasses import dataclass
from typing import Self

from ai_service.domain.errors import DomainError


@dataclass(frozen=True, slots=True, order=True)
class Confidence:
    value: float

    def __post_init__(self) -> None:
        if not math.isfinite(self.value) or not 0.0 <= self.value <= 1.0:
            raise DomainError(f"La confianza debe estar entre 0 y 1 (llegó {self.value!r}).")

    @classmethod
    def clamped(cls, value: float) -> Self:
        """Para lo que devuelve un modelo: se acota en vez de fallar (no admite min/max)."""
        if math.isnan(value):
            return cls(0.0)
        return cls(min(1.0, max(0.0, value)))

    @classmethod
    def zero(cls) -> Self:
        return cls(0.0)

    def is_below(self, threshold: "Confidence") -> bool:
        return self.value < threshold.value

    def __str__(self) -> str:
        # Como lo lee una persona en el panel: 0,72
        return f"{self.value:.2f}".replace(".", ",")
