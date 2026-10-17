"""IA SIMULADA para probar el flujo completo sin API ni coste (solo local y tests).

Reglas por palabras clave (no entiende el texto), las mismas que la IA
simulada de Laravel (`AI_PROVIDER=simulated`, Fase 11). Devuelve tokens
estimados y el modelo `simulated`, que Laravel tiene con un precio ficticio:
así se ven costes en Grafana sin confundirlos con gasto real.

Prohibida en producción (la raíz de composición lo impide): respondería
emails reales sin modelo.
"""

import asyncio
import random
import re
import time
from typing import Any

from ai_service.application.dto import ClassificationResult, DraftContext, DraftResult, LLMCall
from ai_service.domain.category import Category
from ai_service.domain.classification import Classification, Sentiment, Urgency
from ai_service.domain.confidence import Confidence
from ai_service.domain.draft import Draft, Source, SourceType
from ai_service.domain.email import Email
from ai_service.infrastructure.llm import prompts

MODEL = "simulated"

_RULES: tuple[tuple[Category, tuple[str, ...]], ...] = (
    (
        Category.SPAM,
        (
            "bitcoin",
            "has ganado",
            "premio",
            "haz clic",
            "click here",
            "lottery",
            "verifica tu cuenta",
        ),
    ),
    (
        Category.AUTO_REPLY,
        ("fuera de la oficina", "out of office", "de vacaciones", "respuesta automática"),
    ),
    (
        Category.COMPLAINT,
        ("inaceptable", "abogado", "denuncia", "queja", "vergüenza", "unacceptable"),
    ),
    (
        Category.BILLING_ISSUE,
        ("cobrado dos veces", "doble cobro", "cargo duplicado", "charged twice"),
    ),
    (Category.CANCELLATION, ("cancelar", "cancel")),
    (Category.RETURN_REQUEST, ("devolver", "devolución", "return", "refund", "roto", "broken")),
    (Category.INVOICE_REQUEST, ("factura", "invoice")),
    (
        Category.ORDER_STATUS,
        (
            "dónde está mi pedido",
            "donde esta mi pedido",
            "estado de mi pedido",
            "mi pedido",
            "where is my order",
            "my order",
        ),
    ),
    (
        Category.PRODUCT_QUESTION,
        ("envío", "envíos", "enviáis", "ship", "plazo", "garantía", "warranty", "¿", "?"),
    ),
)
_INJECTION = (
    "ignora las instrucciones",
    "ignora tus instrucciones",
    "ignore previous instructions",
    "ignore all previous",
    "system prompt",
    "instrucciones anteriores",
    "</email>",
    "[/email]",
)
_ENGLISH = re.compile(r"\b(the|my|order|please|hello|thank you|do you)\b")
_ORDER = re.compile(r"#?\b(\d{5,6})\b")


class SimulatedLLM:
    """Implementa `LLMPort` sin red."""

    def __init__(self, delay_ms: int = 0) -> None:
        self._delay_ms = delay_ms

    async def classify(self, email: Email) -> ClassificationResult:
        started = time.perf_counter()
        await self._wait()
        text = f"{email.subject}\n{email.body}".lower()
        injection = any(p in text for p in _INJECTION)
        category = next((c for c, words in _RULES if any(w in text for w in words)), Category.OTHER)
        confidence = (
            0.3
            if injection
            else {Category.OTHER: 0.5, Category.PRODUCT_QUESTION: 0.88}.get(category, 0.92)
        )
        needs_human = injection or category in (
            Category.COMPLAINT,
            Category.BILLING_ISSUE,
            Category.OTHER,
        )
        classification = Classification(
            category=category,
            confidence=Confidence(confidence),
            language="en" if _ENGLISH.search(text) else "es",
            summary=f"[IA simulada] {email.subject or 'Email sin asunto'}"[:200],
            sentiment=Sentiment.NEGATIVE if category is Category.COMPLAINT else Sentiment.NEUTRAL,
            urgency=Urgency.HIGH if category is Category.COMPLAINT else Urgency.NORMAL,
            injection_suspected=injection,
            needs_human=needs_human,
            needs_human_reason="[IA simulada] Caso que requiere a una persona."
            if needs_human
            else None,
            order_numbers=tuple(dict.fromkeys(_ORDER.findall(email.body))),
        )
        call = self._call(
            "ai_service.classification", prompts.CLASSIFICATION.ref, text, 120, started
        )
        return ClassificationResult(classification, (call,))

    async def draft(
        self, email: Email, classification: Classification, context: DraftContext
    ) -> DraftResult:
        started = time.perf_counter()
        await self._wait()
        english = classification.language == "en"
        company = email.tenant_name or "la empresa"
        # Solo "confía" si tiene datos: documentación o pedidos consultados.
        best = context.knowledge[0] if context.knowledge else None
        orders = _orders(context)
        facts = [best.content] if best else []
        facts += [_order_fact(order, english=english) for order in orders]
        fact = "".join(f"\n\n{f}" for f in facts)
        if english:
            body = f"Hello,\n\nThank you for your message. [Simulated AI reply]{fact}\n\nBest regards,\nThe {company} customer service team"
        else:
            body = f"Hola:\n\nGracias por escribirnos. [Respuesta de la IA simulada]{fact}\n\nUn saludo,\nEl equipo de atención al cliente de {company}"
        sources = [Source(SourceType.DOCUMENT, best.source)] if best else []
        sources += [Source(SourceType.ORDER, str(order["order_number"])) for order in orders]
        confident = bool(facts)
        draft = Draft(
            body=body,
            language=classification.language,
            confidence=Confidence(0.9 if confident else 0.5),
            needs_human=not confident,
            needs_human_reason=None
            if confident
            else "[IA simulada] No hay datos suficientes para responder.",
            sources=tuple(sources),
        )
        call = self._call(
            "ai_service.draft", prompts.DRAFT.ref, email.body, len(body) // 4, started
        )
        return DraftResult(draft, (call,))

    async def _wait(self) -> None:
        if self._delay_ms:
            await asyncio.sleep(random.uniform(0.5, 1.5) * self._delay_ms / 1000)  # noqa: S311

    @staticmethod
    def _call(purpose: str, prompt: str, text: str, output: int, started: float) -> LLMCall:
        return LLMCall(
            purpose=purpose,
            provider="simulated",
            requested_model=MODEL,
            model=MODEL,
            status="success",
            input_tokens=500 + len(text) // 4,
            output_tokens=output,
            latency_ms=int((time.perf_counter() - started) * 1000),
            stop_reason="end_turn",
            provider_request_id=f"sim_{int(time.time() * 1000)}",
            prompt=prompt,
        )


def _orders(context: DraftContext) -> list[dict[str, Any]]:
    """Pedidos devueltos con éxito por get_order / get_customer_orders."""
    found: list[dict[str, Any]] = []
    for record in context.tool_results:
        if record.status != "success" or not record.result:
            continue
        if record.tool == "get_order" and record.result.get("order_number"):
            found.append(record.result)
        elif record.tool == "get_customer_orders":
            found += [o for o in record.result.get("orders", []) if isinstance(o, dict)][:1]
    return [o for o in found if o.get("order_number")]


def _order_fact(order: dict[str, Any], *, english: bool) -> str:
    number = order["order_number"]
    status = order.get("status") if english else order.get("status_label") or order.get("status")
    shipment = order.get("shipment") or {}
    if english:
        text = f"Order #{number}: {status}."
        if shipment.get("carrier"):
            text += f" Carrier {shipment['carrier']}, tracking {shipment.get('tracking_number')}."
    else:
        text = f"Pedido #{number}: {status}."
        if shipment.get("carrier"):
            text += f" Transportista {shipment['carrier']}, seguimiento {shipment.get('tracking_number')}."
    return text
