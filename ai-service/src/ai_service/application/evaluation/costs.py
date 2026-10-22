"""Coste de una evaluación con la tabla de precios de Laravel.

Una sola tabla de precios en la plataforma (`config/ai.php` → `pricing`, la
que usa `CostCalculator`): aquí se lee del texto de ese fichero en vez de
copiarla, para que la evaluación y la facturación nunca difieran.
"""

import re
from collections.abc import Iterable
from dataclasses import dataclass

from ai_service.application.dto import LLMCall

_LINE = re.compile(
    r"'([\w.-]+)'\s*=>\s*\[\s*'input'\s*=>\s*([\d.]+),\s*'output'\s*=>\s*([\d.]+),"
    r"\s*'cache_write'\s*=>\s*([\d.]+),\s*'cache_read'\s*=>\s*([\d.]+)\s*\]"
)
FREE_PROVIDERS = frozenset({"local", "ollama"})  # embeddings locales: sin coste por token


@dataclass(frozen=True, slots=True)
class Price:
    """USD por millón de tokens."""

    input: float
    output: float
    cache_write: float
    cache_read: float


def parse_pricing(php: str) -> dict[str, Price]:
    return {
        m[1]: Price(float(m[2]), float(m[3]), float(m[4]), float(m[5])) for m in _LINE.finditer(php)
    }


def cost_usd(calls: Iterable[LLMCall], prices: dict[str, Price]) -> float | None:
    """Suma de lo facturado; None si algún modelo con tokens no tiene precio
    (desconocido, nunca 0: mismo criterio que CostCalculator)."""
    total = 0.0
    for call in calls:
        if call.provider in FREE_PROVIDERS:
            continue
        price = prices.get(call.model)
        tokens = (
            call.input_tokens
            + call.output_tokens
            + call.cache_write_tokens
            + call.cache_read_tokens
        )
        if price is None:
            if tokens:
                return None
            continue
        total += (
            call.input_tokens * price.input
            + call.output_tokens * price.output
            + call.cache_write_tokens * price.cache_write
            + call.cache_read_tokens * price.cache_read
        ) / 1_000_000
    return round(total, 6)
