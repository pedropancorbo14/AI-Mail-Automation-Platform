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
from collections.abc import Sequence
from dataclasses import replace
from datetime import datetime
from typing import Any

from ai_service.application.dto import (
    ClassificationResult,
    DraftContext,
    DraftConversation,
    DraftResult,
    LLMCall,
    ToolAnswer,
    ToolRequest,
    ToolSpec,
)
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
    (
        Category.TECHNICAL_SUPPORT,
        ("no funciona", "no enciende", "no arranca", "not working", "doesn't work"),
    ),
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
# Con límites de palabra: "solicitar" contiene "cita". Va después de spam,
# respuestas automáticas y quejas, y antes que cancelar ("cancelar la cita").
_APPOINTMENT = re.compile(r"\b(citas?|appointments?)\b")
# Pedir reservar, cambiar o cancelar una cita: la IA solo informa (regla de
# email_draft@7) → acción pendiente y una persona.
_APPOINTMENT_CHANGE = re.compile(
    r"\b(cancel\w*|anul\w*|cambi\w*|mov\w*|reserv\w*|pedir|reschedul\w*|book\w*|change)\b"
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
# Un número de factura (FAC-44102) no es un número de pedido.
_ORDER = re.compile(r"(?<!FAC-)(?<!FAC)#?\b(\d{5,6})\b", re.IGNORECASE)
_INVOICE = re.compile(r"\bFAC-?(\d{5,6})\b", re.IGNORECASE)


class SimulatedLLM:
    """Implementa `LLMPort` sin red."""

    def __init__(self, delay_ms: int = 0) -> None:
        self._delay_ms = delay_ms

    async def classify(self, email: Email) -> ClassificationResult:
        started = time.perf_counter()
        await self._wait()
        text = f"{email.subject}\n{email.body}".lower()
        injection = any(p in text for p in _INJECTION)
        category = _category(text)
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
            invoice_numbers=tuple(dict.fromkeys(f"FAC-{n}" for n in _INVOICE.findall(email.body))),
        )
        call = self._call(
            "ai_service.classification", prompts.CLASSIFICATION.ref, text, 120, started
        )
        return ClassificationResult(classification, (call,))

    async def draft(
        self,
        email: Email,
        classification: Classification,
        context: DraftContext,
        *,
        tools: Sequence[ToolSpec] = (),
        conversation: DraftConversation | None = None,
        answers: Sequence[ToolAnswer] = (),
    ) -> DraftResult:
        started = time.perf_counter()
        await self._wait()
        # Lo que devolvieron las consultas que pidió en rondas anteriores, como datos.
        consulted = (*conversation.turns, *answers) if conversation else tuple(answers)
        records = tuple(a.record for a in consulted if isinstance(a, ToolAnswer))
        context = replace(context, tool_results=(*context.tool_results, *records))
        request = _wanted_lookup(email, classification, context, {t.name for t in tools}, records)
        if request is not None:
            call = self._call("ai_service.draft", prompts.DRAFT.ref, email.body, 30, started)
            return DraftResult(
                draft=None,
                calls=(call,),
                tool_requests=(request,),
                conversation=DraftConversation(turns=consulted),
            )
        english = classification.language == "en"
        company = email.tenant_name or "la empresa"
        # Solo "confía" si tiene datos: documentación, pedidos o un ticket abierto.
        best = context.knowledge[0] if context.knowledge else None
        orders = _orders(context)
        invoices = _invoices(context)
        tickets = _created_tickets(context)
        appointments = _appointments(context)
        facts = [best.content] if best else []
        # Documentación que buscó en una ronda anterior (search_company_documents).
        searched = [] if best else _searched_docs(context)
        facts += [content for _, content in searched]
        facts += [_order_fact(order, english=english) for order in orders]
        facts += [_invoice_fact(invoice, english=english) for invoice in invoices]
        facts += [_appointment_fact(a, english=english) for a in appointments]
        facts += [
            f"We have opened ticket {t['id']} for you."
            if english
            else f"Hemos abierto la incidencia {t['id']} para tu caso."
            for t in tickets
        ]
        fact = "".join(f"\n\n{f}" for f in facts)
        if english:
            body = f"Hello,\n\nThank you for your message. [Simulated AI reply]{fact}\n\nBest regards,\nThe {company} customer service team"
        else:
            body = f"Hola:\n\nGracias por escribirnos. [Respuesta de la IA simulada]{fact}\n\nUn saludo,\nEl equipo de atención al cliente de {company}"
        sources = [Source(SourceType.DOCUMENT, best.source)] if best else []
        sources += [Source(SourceType.DOCUMENT, source) for source, _ in searched]
        sources += [Source(SourceType.ORDER, str(order["order_number"])) for order in orders]
        sources += [Source(SourceType.INVOICE, str(i["invoice_number"])) for i in invoices]
        sources += [Source(SourceType.TICKET, str(t["id"])) for t in tickets]
        sources += [Source(SourceType.APPOINTMENT, str(a["appointment_id"])) for a in appointments]
        confident = bool(facts)
        change = classification.category is Category.APPOINTMENT and bool(
            _APPOINTMENT_CHANGE.search(f"{email.subject}\n{email.body}".lower())
        )
        reason = None
        if change:
            reason = (
                "[IA simulada] Pide reservar, cambiar o cancelar una cita: lo gestiona una persona."
            )
        elif not confident:
            reason = "[IA simulada] No hay datos suficientes para responder."
        draft = Draft(
            body=body,
            language=classification.language,
            confidence=Confidence(0.9 if confident else 0.5),
            needs_human=reason is not None,
            needs_human_reason=reason,
            pending_actions=("Gestionar el cambio de la cita que pide el paciente.",)
            if change
            else (),
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


def _category(text: str) -> Category:
    for category, words in _RULES:
        if category is Category.CANCELLATION and _APPOINTMENT.search(text):
            return Category.APPOINTMENT
        if any(w in text for w in words):
            return category
    return Category.APPOINTMENT if _APPOINTMENT.search(text) else Category.OTHER


def _wanted_lookup(
    email: Email,
    classification: Classification,
    context: DraftContext,
    offered: set[str],
    already: tuple[Any, ...],
) -> ToolRequest | None:
    """Reglas de la IA simulada para pedir una consulta antes de redactar (una vez):

    - Tiene la lista de pedidos pero no el detalle → `get_order` del más reciente.
    - Soporte técnico sin documentación → `search_company_documents` con el asunto.
    """
    if already:
        return None
    listed: list[dict[str, Any]] = next(
        (
            r.result.get("orders", [])
            for r in context.tool_results
            if r.tool == "get_customer_orders" and r.status == "success" and r.result
        ),
        [],
    )
    has_detail = any(r.tool == "get_order" and r.status == "success" for r in context.tool_results)
    if "get_order" in offered and listed and not has_detail:
        number = str(listed[0].get("order_number", ""))
        return ToolRequest("sim-1", "get_order", {"order_number": number})
    if (
        "search_company_documents" in offered
        and not context.knowledge
        and classification.category is Category.TECHNICAL_SUPPORT
    ):
        return ToolRequest("sim-1", "search_company_documents", {"query": email.subject})
    return None


def _orders(context: DraftContext) -> list[dict[str, Any]]:
    """Pedidos devueltos con éxito por get_order / get_customer_orders."""
    details: list[dict[str, Any]] = []
    summaries: list[dict[str, Any]] = []
    for record in context.tool_results:
        if record.status != "success" or not record.result:
            continue
        if record.tool == "get_order" and record.result.get("order_number"):
            details.append(record.result)
        elif record.tool == "get_customer_orders":
            summaries += [o for o in record.result.get("orders", []) if isinstance(o, dict)][:1]
    # Si el detalle y el resumen son del mismo pedido, gana el detalle.
    unique: dict[str, dict[str, Any]] = {}
    for order in (*details, *summaries):
        if order.get("order_number"):
            unique.setdefault(str(order["order_number"]), order)
    return list(unique.values())


def _searched_docs(context: DraftContext) -> list[tuple[str, str]]:
    """(fuente, texto) del primer fragmento de cada búsqueda en la documentación."""
    docs: list[tuple[str, str]] = []
    for record in context.tool_results:
        if record.tool != "search_company_documents" or record.status != "success":
            continue
        rows = (record.result or {}).get("results") or []
        if rows and isinstance(rows[0], dict) and rows[0].get("source"):
            docs.append((str(rows[0]["source"]), str(rows[0].get("content", ""))))
    return docs


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


def _invoices(context: DraftContext) -> list[dict[str, Any]]:
    """Facturas devueltas con éxito por get_invoice / get_customer_invoices."""
    found: list[dict[str, Any]] = []
    for record in context.tool_results:
        if record.status != "success" or not record.result:
            continue
        if record.tool == "get_invoice":
            found.append(record.result)
        elif record.tool == "get_customer_invoices":
            found += [i for i in record.result.get("invoices", []) if isinstance(i, dict)][:1]
    return [i for i in found if i.get("invoice_number")]


def _invoice_fact(invoice: dict[str, Any], *, english: bool) -> str:
    number, total, currency = (
        invoice["invoice_number"],
        invoice.get("total"),
        invoice.get("currency"),
    )
    if english:
        return f"Invoice {number}: {total} {currency}, {invoice.get('status')}."
    return f"Factura {number}: {total} {currency}, {invoice.get('status_label')}."


def _appointments(context: DraftContext) -> list[dict[str, Any]]:
    """La cita de get_appointment o, de la lista, la próxima (si no hay, la más reciente)."""
    for record in context.tool_results:
        if record.status != "success" or not record.result:
            continue
        if record.tool == "get_appointment" and record.result.get("appointment_id"):
            return [record.result]
        if record.tool == "get_customer_appointments":
            rows = [a for a in record.result.get("appointments", []) if isinstance(a, dict)]
            upcoming = [a for a in rows if a.get("upcoming")]
            # Las próximas vienen de la más lejana a la más cercana: la última es la siguiente.
            chosen = upcoming[-1:] or rows[:1]
            return [a for a in chosen if a.get("appointment_id")]
    return []


def _appointment_fact(appointment: dict[str, Any], *, english: bool) -> str:
    try:
        start = datetime.fromisoformat(str(appointment["starts_at"]))
        when = (
            f"{start:%d/%m/%Y} at {start:%H:%M}"
            if english
            else f"{start:%d/%m/%Y} a las {start:%H:%M}"
        )
    except (KeyError, ValueError):
        when = str(appointment.get("starts_at"))
    details = ", ".join(
        str(appointment[k]) for k in ("professional", "location") if appointment.get(k)
    )
    label = appointment.get("status") if english else appointment.get("status_label")
    name = appointment["appointment_id"]
    service = f" ({appointment['service']})" if appointment.get("service") else ""
    if english:
        return f"Appointment {name}{service}: {when}. {details}. Status: {label}."
    return f"Cita {name}{service}: {when}. {details}. Estado: {label}."


def _created_tickets(context: DraftContext) -> list[dict[str, Any]]:
    """Tickets abiertos por una acción aprobada (create_support_ticket)."""
    found: list[dict[str, Any]] = []
    for record in context.tool_results:
        ticket = (record.result or {}).get("ticket") if record.status == "success" else None
        if record.tool == "create_support_ticket" and isinstance(ticket, dict) and ticket.get("id"):
            found.append(ticket)
    return found
