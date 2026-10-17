"""Constructores de objetos del dominio para los tests (valores por defecto realistas)."""

from datetime import UTC, datetime
from typing import Any

from ai_service.domain.category import Category
from ai_service.domain.classification import Classification, Sentiment, Urgency
from ai_service.domain.confidence import Confidence
from ai_service.domain.draft import Draft
from ai_service.domain.email import Email, EmailAddress, TenantId


def make_email(**overrides: Any) -> Email:
    values: dict[str, Any] = {
        "email_id": 42,
        "tenant_id": TenantId(1),
        "sender": EmailAddress("cliente@cliente.test"),
        "subject": "¿Hacéis envíos a Canarias?",
        "body": "Hola, ¿enviáis a Tenerife? ¿Cuánto cuesta? Gracias.",
        "received_at": datetime(2026, 10, 17, 10, 0, tzinfo=UTC),
        "tenant_name": "Empresa Demo",
    }
    return Email(**(values | overrides))


def make_classification(**overrides: Any) -> Classification:
    values: dict[str, Any] = {
        "category": Category.PRODUCT_QUESTION,
        "confidence": Confidence(0.95),
        "language": "es",
        "summary": "Pregunta si hay envíos a Canarias y su coste.",
        "sentiment": Sentiment.NEUTRAL,
        "urgency": Urgency.NORMAL,
        "injection_suspected": False,
        "needs_human": False,
    }
    return Classification(**(values | overrides))


def make_draft(**overrides: Any) -> Draft:
    values: dict[str, Any] = {
        "body": "Hola:\n\nSí, enviamos a Canarias. Un saludo,\nEl equipo de Empresa Demo",
        "language": "es",
        "confidence": Confidence(0.93),
    }
    return Draft(**(values | overrides))
