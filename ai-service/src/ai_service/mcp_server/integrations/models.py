"""Datos de negocio en un formato común para todos los proveedores.

Cada proveedor (datos de demostración, CSV, API estándar, WooCommerce…)
traduce sus datos a estos objetos; las herramientas solo conocen este
formato. Llevan el email del cliente para que la capa de herramientas pueda
comprobar **siempre** que el dato es del cliente que escribe, aunque el
proveedor ya lo haya filtrado.
"""

from dataclasses import dataclass


@dataclass(frozen=True, slots=True)
class OrderItem:
    name: str
    quantity: int
    unit_price: float | None = None
    sku: str | None = None


@dataclass(frozen=True, slots=True)
class Shipment:
    carrier: str | None = None
    tracking_number: str | None = None
    tracking_url: str | None = None
    shipped_at: str | None = None
    estimated_delivery: str | None = None
    delivered_at: str | None = None


@dataclass(frozen=True, slots=True)
class Order:
    number: str
    customer_email: str
    status: str  # pending_payment, processing, shipped, delivered, cancelled, returned
    placed_at: str | None = None
    customer_name: str | None = None
    total: float | None = None
    currency: str | None = None
    items: tuple[OrderItem, ...] = ()
    shipment: Shipment | None = None
    # Sin dirección completa: para responder basta con ciudad y país.
    shipping_city: str | None = None
    shipping_country: str | None = None


@dataclass(frozen=True, slots=True)
class Invoice:
    number: str
    customer_email: str
    status: str  # paid, refunded (abono), pending
    order_number: str | None = None
    issued_at: str | None = None
    total: float | None = None
    currency: str | None = None


@dataclass(frozen=True, slots=True)
class Appointment:
    id: str
    customer_email: str
    status: str  # scheduled, cancelled, completed, no_show
    starts_at: str  # ISO 8601 con zona horaria
    ends_at: str | None = None
    customer_name: str | None = None
    service: str | None = None
    professional: str | None = None
    # Lugar de la empresa (centro, sala, "Videollamada"), nunca la dirección del cliente.
    location: str | None = None
    # Solo lo que el cliente puede leer: nunca notas internas ni clínicas.
    instructions: str | None = None
    price: float | None = None
    currency: str | None = None


@dataclass(frozen=True, slots=True)
class Ticket:
    id: str
    customer_email: str
    status: str  # open, pending, closed
    subject: str | None = None
    priority: str | None = None  # low, normal, high
    created_at: str | None = None
    last_update: str | None = None


@dataclass(frozen=True, slots=True)
class NewTicket:
    """Ticket que se quiere abrir. `idempotency_key` = la acción aprobada:
    repetirla (Redis entrega al menos una vez) no debe crear otro ticket."""

    customer_email: str
    subject: str
    description: str
    priority: str
    idempotency_key: str


@dataclass(frozen=True, slots=True)
class CreatedTicket:
    ticket: Ticket
    created: bool  # False = ya existía para esa clave de idempotencia
