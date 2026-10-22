"""Herramientas de negocio: las reglas comunes a todos los proveedores.

Cada operación recibe la empresa (`tenant_id`) y el cliente (`customer_email`)
del email que se procesa (los pone ai-service, nunca el modelo), pide los
datos al proveedor de esa empresa y **solo** devuelve datos de ese cliente:
aunque el proveedor devuelva un pedido de otra persona, aquí se descarta. Un
pedido (o cita) de otro cliente se responde igual que uno que no existe (ni
siquiera se puede averiguar si un número existe). También aquí: normalización
de números, límites, validación de tickets y lo que ve la IA (sin dirección
completa).
"""

import re
from collections.abc import Callable
from dataclasses import asdict
from datetime import UTC, datetime
from typing import Any

from ai_service.mcp_server.integrations.contracts import (
    IntegrationResolver,
    TenantIntegrations,
)
from ai_service.mcp_server.integrations.models import (
    Appointment,
    Invoice,
    NewTicket,
    Order,
    Ticket,
)

STATUS_LABELS = {
    "pending_payment": "Pendiente de pago",
    "processing": "En preparación",
    "shipped": "Enviado",
    "delivered": "Entregado",
    "cancelled": "Cancelado",
    "returned": "Devuelto",
}
TICKET_LABELS = {"open": "Abierto", "pending": "Esperando al cliente", "closed": "Cerrado"}
INVOICE_LABELS = {"paid": "Pagada", "refunded": "Abonada (devolución)", "pending": "Pendiente"}
APPOINTMENT_LABELS = {
    "scheduled": "Programada",
    "cancelled": "Cancelada",
    "completed": "Realizada",
    "no_show": "El cliente no acudió",
}
PRIORITIES = frozenset({"low", "normal", "high"})

NOT_FOUND = "No hay ningún pedido con ese número asociado a este cliente."
INVOICE_NOT_FOUND = "No hay ninguna factura con ese número asociada a este cliente."
APPOINTMENT_NOT_FOUND = "No hay ninguna cita con ese identificador asociada a este cliente."
NO_HELPDESK = "Esta empresa no tiene un sistema de tickets configurado."
# Pedidos que se leen para la ficha del cliente (recuento y fechas).
CUSTOMER_SCAN = 100


class ToolInputError(ValueError):
    """Error que el modelo debe leer (p. ej. pedido no encontrado)."""


def normalize_order_number(value: str) -> str | None:
    """Primer fragmento con dígitos: "#45821", "nº 45821", "pedido 45821", "ES-1234"."""
    match = re.search(r"[A-Za-z0-9-]*\d[A-Za-z0-9-]*", value)
    return match.group(0).strip("-").upper() if match else None


def _same(a: str, b: str) -> bool:
    return a.strip().casefold() == b.strip().casefold()


def _limit(value: int) -> int:
    return max(1, min(value, 10))


class BusinessTools:
    def __init__(
        self,
        resolver: IntegrationResolver,
        *,
        clock: Callable[[], datetime] = lambda: datetime.now(UTC),
    ) -> None:
        self._resolver = resolver
        # Para marcar qué citas están por llegar: la IA no tiene que calcularlo.
        self._clock = clock

    async def _integrations(self, tenant_id: int) -> TenantIntegrations:
        return await self._resolver.for_tenant(tenant_id)

    async def _orders(self, tenant_id: int, customer: str, limit: int) -> list[Order]:
        source = (await self._integrations(tenant_id)).orders
        if source is None:
            return []
        rows = await source.customer_orders(customer.strip().lower(), limit)
        return [o for o in rows if _same(o.customer_email, customer)][:limit]

    async def get_order(self, tenant_id: int, customer: str, order_number: str) -> dict[str, Any]:
        number = normalize_order_number(order_number)
        source = (await self._integrations(tenant_id)).orders
        if number is None or source is None:
            raise ToolInputError(NOT_FOUND)
        order = await source.find_order(number, customer.strip().lower())
        if order is None or not _same(order.customer_email, customer):
            raise ToolInputError(NOT_FOUND)
        return present_order(order)

    async def get_customer_orders(
        self, tenant_id: int, customer: str, limit: int
    ) -> dict[str, Any]:
        orders = await self._orders(tenant_id, customer, _limit(limit))
        result: dict[str, Any] = {"orders": [summarize_order(o) for o in orders]}
        if not orders:
            result["note"] = "Este cliente no tiene pedidos. No inventes ninguno."
        return result

    async def get_invoice(
        self, tenant_id: int, customer: str, invoice_number: str
    ) -> dict[str, Any]:
        number = normalize_order_number(invoice_number)
        source = (await self._integrations(tenant_id)).invoices
        if number is None or source is None:
            raise ToolInputError(INVOICE_NOT_FOUND)
        invoice = await source.find_invoice(number, customer.strip().lower())
        if invoice is None or not _same(invoice.customer_email, customer):
            raise ToolInputError(INVOICE_NOT_FOUND)
        return present_invoice(invoice)

    async def get_customer_invoices(
        self, tenant_id: int, customer: str, limit: int
    ) -> dict[str, Any]:
        source = (await self._integrations(tenant_id)).invoices
        rows = (
            await source.customer_invoices(customer.strip().lower(), _limit(limit))
            if source
            else []
        )
        invoices = [present_invoice(i) for i in rows if _same(i.customer_email, customer)]
        result: dict[str, Any] = {"invoices": invoices[: _limit(limit)]}
        if not invoices:
            result["note"] = "Este cliente no tiene facturas. No inventes ninguna."
        return result

    async def get_appointment(
        self, tenant_id: int, customer: str, appointment_id: str
    ) -> dict[str, Any]:
        wanted = normalize_order_number(appointment_id)
        source = (await self._integrations(tenant_id)).appointments
        if wanted is None or source is None:
            raise ToolInputError(APPOINTMENT_NOT_FOUND)
        appointment = await source.find_appointment(wanted, customer.strip().lower())
        if appointment is None or not _same(appointment.customer_email, customer):
            raise ToolInputError(APPOINTMENT_NOT_FOUND)
        return present_appointment(appointment, self._clock())

    async def get_customer_appointments(
        self, tenant_id: int, customer: str, limit: int
    ) -> dict[str, Any]:
        source = (await self._integrations(tenant_id)).appointments
        rows = (
            await source.customer_appointments(customer.strip().lower(), _limit(limit))
            if source
            else []
        )
        now = self._clock()
        mine = [a for a in rows if _same(a.customer_email, customer)][: _limit(limit)]
        result: dict[str, Any] = {"appointments": [present_appointment(a, now) for a in mine]}
        if not mine:
            result["note"] = "Este cliente no tiene citas. No inventes ninguna."
        return result

    async def get_customer(self, tenant_id: int, customer: str) -> dict[str, Any]:
        orders = await self._orders(tenant_id, customer, CUSTOMER_SCAN)
        if not orders:
            return {"found": False, "note": "No hay ningún cliente con este email en la empresa."}
        return {
            "found": True,
            "name": next((o.customer_name for o in orders if o.customer_name), None),
            "email": customer.strip().lower(),
            "orders_count": len(orders),
            "first_order_at": orders[-1].placed_at,
            "last_order_at": orders[0].placed_at,
        }

    async def get_customer_tickets(
        self, tenant_id: int, customer: str, limit: int
    ) -> dict[str, Any]:
        source = (await self._integrations(tenant_id)).tickets
        rows = (
            await source.customer_tickets(customer.strip().lower(), _limit(limit)) if source else []
        )
        mine = [t for t in rows if _same(t.customer_email, customer)][: _limit(limit)]
        return {"tickets": [present_ticket(t) for t in mine]}

    async def create_ticket(
        self,
        tenant_id: int,
        customer: str,
        subject: str,
        description: str,
        priority: str,
        idempotency_key: str,
    ) -> dict[str, Any]:
        """Abre un ticket. Repetir la llamada con la misma clave devuelve el mismo
        ticket (una reanudación repetida no crea dos)."""
        source = (await self._integrations(tenant_id)).tickets
        if source is None:
            raise ToolInputError(NO_HELPDESK)
        if not subject.strip() or len(subject) > 150 or len(description) > 2000:
            raise ToolInputError(
                "Asunto (de 1 a 150 caracteres) o descripción (máx. 2000) no válidos."
            )
        if priority not in PRIORITIES:
            raise ToolInputError("Prioridad no válida (low, normal o high).")
        result = await source.create_ticket(
            NewTicket(
                customer_email=customer.strip().lower(),
                subject=subject.strip(),
                description=description,
                priority=priority,
                idempotency_key=idempotency_key,
            )
        )
        return {"created": result.created, "ticket": present_ticket(result.ticket)}

    async def availability(self, tenant_id: int) -> dict[str, bool]:
        """Qué herramientas tienen datos para la empresa (el panel lo muestra:
        activar una sin datos no sirve). La ficha del cliente sale de los pedidos."""
        found = await self._integrations(tenant_id)
        has_orders = found.orders is not None
        has_invoices = found.invoices is not None
        has_helpdesk = found.tickets is not None
        has_appointments = found.appointments is not None
        return {
            "get_order": has_orders,
            "get_customer_orders": has_orders,
            "get_customer": has_orders,
            "get_invoice": has_invoices,
            "get_customer_invoices": has_invoices,
            "get_appointment": has_appointments,
            "get_customer_appointments": has_appointments,
            "get_customer_tickets": has_helpdesk,
            "create_support_ticket": has_helpdesk,
        }


def summarize_order(order: Order) -> dict[str, Any]:
    return {
        "order_number": order.number,
        "status": order.status,
        "status_label": STATUS_LABELS.get(order.status, order.status),
        "placed_at": order.placed_at,
        "total": order.total,
        "currency": order.currency,
    }


def present_order(order: Order) -> dict[str, Any]:
    """Lo que necesita la IA para responder (sin el email ni dirección completa)."""
    return {
        **summarize_order(order),
        "items": [asdict(i) for i in order.items],
        "shipment": asdict(order.shipment) if order.shipment else None,
        "shipping_city": order.shipping_city,
        "shipping_country": order.shipping_country,
    }


def present_invoice(invoice: Invoice) -> dict[str, Any]:
    return {
        "invoice_number": invoice.number,
        "order_number": invoice.order_number,
        "issued_at": invoice.issued_at,
        "total": invoice.total,
        "currency": invoice.currency,
        "status": invoice.status,
        "status_label": INVOICE_LABELS.get(invoice.status, invoice.status),
    }


def present_appointment(appointment: Appointment, now: datetime) -> dict[str, Any]:
    """Lo que necesita la IA (sin el email). `upcoming` = programada y aún no
    ha empezado: así no tiene que comparar fechas por su cuenta."""
    try:
        upcoming = (
            appointment.status == "scheduled"
            and datetime.fromisoformat(appointment.starts_at) > now
        )
    except ValueError:
        upcoming = False
    return {
        "appointment_id": appointment.id,
        "status": appointment.status,
        "status_label": APPOINTMENT_LABELS.get(appointment.status, appointment.status),
        "upcoming": upcoming,
        "starts_at": appointment.starts_at,
        "ends_at": appointment.ends_at,
        "service": appointment.service,
        "professional": appointment.professional,
        "location": appointment.location,
        "instructions": appointment.instructions,
        "price": appointment.price,
        "currency": appointment.currency,
    }


def present_ticket(ticket: Ticket) -> dict[str, Any]:
    return {
        "id": ticket.id,
        "subject": ticket.subject,
        "status": ticket.status,
        "status_label": TICKET_LABELS.get(ticket.status, ticket.status),
        "priority": ticket.priority,
        "created_at": ticket.created_at,
        "last_update": ticket.last_update,
    }
