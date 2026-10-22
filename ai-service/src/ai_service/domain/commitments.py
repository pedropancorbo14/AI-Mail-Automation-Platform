"""Detección de compromisos que la IA no puede adquirir.

La IA solo informa: nunca promete reembolsos ni confirma cancelaciones (eso
lo decide y lo hace una persona). Reglas deterministas, sin coste de IA: si
el borrador las contiene, lo revisa una persona.
"""

import re
from dataclasses import dataclass


@dataclass(frozen=True, slots=True)
class _Rule:
    reason: str
    patterns: tuple[re.Pattern[str], ...]


def _compile(*patterns: str) -> tuple[re.Pattern[str], ...]:
    return tuple(re.compile(p, re.IGNORECASE) for p in patterns)


_RULES = (
    _Rule(
        "La respuesta promete un reembolso (solo puede decidirlo una persona).",
        _compile(
            r"\bte\s+(devolveremos|reembolsaremos|abonaremos)\b",
            r"\b(procederemos|vamos)\s+a\s+(devolverte|reembolsar|abonar|realizar\s+el\s+reembolso)",
            r"\bhemos\s+(tramitado|realizado|emitido|procesado|aprobado)\s+(el|tu|un)\s+(reembolso|abono)",
            r"\b(recibirás|tendrás)\s+(el|tu|un)\s+(reembolso|abono)\b",
            r"\b(reembolso|abono)\s+(está|queda|ha\s+sido)\s+(aprobado|tramitado|realizado|procesado)",
            r"\bwe\s*(will|'ll)\s+(refund|reimburse|issue\s+(you\s+)?a\s+refund)",
            r"\b(your\s+)?refund\s+(has\s+been|was|is\s+being)\s+(processed|issued|approved)",
            r"\byou\s*(will|'ll)\s+(receive|get)\s+(a|your)\s+(full\s+)?refund",
        ),
    ),
    _Rule(
        "La respuesta confirma una cancelación (solo puede hacerla una persona).",
        _compile(
            r"\bhemos\s+cancelado\s+(tu|el|su|la)\s+(pedido|suscripción|contrato|cita)",
            r"\b(tu|el|su|la)\s+(pedido|suscripción|contrato|cita)\s+(ha\s+sido|queda|está)"
            r"\s+cancelad[oa]",
            r"\bwe\s*(have|'ve)\s+cancell?ed\s+your\b",
            r"\byour\s+(order|subscription|appointment)\s+(has\s+been|is)\s+cancell?ed",
        ),
    ),
    _Rule(
        "La respuesta confirma que una cita se ha reservado o cambiado"
        " (solo puede hacerlo una persona).",
        _compile(
            r"\bhemos\s+(cambiado|movido|modificado|reprogramado|reservado)\s+(tu|su|la)\s+cita",
            r"\b(tu|su|la)\s+cita\s+(ha\s+sido|queda|está)\s+"
            r"(cambiada|movida|modificada|reprogramada|reservada)",
            r"\bwe\s*(have|'ve)\s+(moved|rescheduled|changed|booked)\s+your\s+appointment",
            r"\byour\s+appointment\s+(has\s+been|is)\s+(moved|rescheduled|changed|booked)",
        ),
    ),
)


def forbidden_commitments(text: str) -> tuple[str, ...]:
    """Motivos (uno por tipo de compromiso) encontrados en el texto."""
    return tuple(rule.reason for rule in _RULES if any(p.search(text) for p in rule.patterns))
