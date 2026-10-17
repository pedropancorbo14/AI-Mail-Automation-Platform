"""Decisión recomendada sobre un email (Laravel tiene la última palabra)."""

from dataclasses import dataclass
from enum import StrEnum


class Decision(StrEnum):
    AUTO_SEND = "auto_send"  # se puede enviar sola si las reglas de Laravel lo permiten
    HUMAN_REVIEW = "human_review"  # la revisa una persona
    ESCALATE = "escalate"  # caso delicado: a una persona, con prioridad


@dataclass(frozen=True, slots=True)
class DecisionOutcome:
    decision: Decision
    reasons: tuple[str, ...] = ()
