"""Interfaces que implementa cada proveedor de datos de una empresa.

Un proveedor **solo trae datos**: las reglas (solo el cliente que escribe,
mismo mensaje para un pedido ajeno que para uno inexistente, límites,
validación de tickets, lo que ve la IA) están en `BusinessTools` y se aplican
igual a todos los proveedores. Así un proveedor con un fallo de filtrado no
puede enseñar datos de otro cliente.

Asíncronas porque los proveedores reales llaman a APIs externas.
"""

from dataclasses import dataclass
from typing import Protocol

from ai_service.mcp_server.integrations.models import (
    Appointment,
    CreatedTicket,
    Invoice,
    NewTicket,
    Order,
    Ticket,
)


class IntegrationError(Exception):
    """El sistema de la empresa no responde o rechaza la petición (credenciales,
    caída, formato inesperado). El mensaje es apto para la IA y el panel: sin
    credenciales ni detalles internos."""


class OrderSource(Protocol):
    async def find_order(self, number: str, customer_email: str) -> Order | None:
        """Pedido por su número (ya normalizado). Se pasa el cliente por si el
        sistema lo exige para buscar; la capa de herramientas lo vuelve a
        comprobar."""
        ...

    async def customer_orders(self, customer_email: str, limit: int) -> list[Order]:
        """Pedidos del cliente, del más reciente al más antiguo."""
        ...


class InvoiceSource(Protocol):
    async def find_invoice(self, number: str, customer_email: str) -> Invoice | None: ...

    async def customer_invoices(self, customer_email: str, limit: int) -> list[Invoice]:
        """Facturas del cliente, de la más reciente a la más antigua."""
        ...


class AppointmentSource(Protocol):
    async def find_appointment(
        self, appointment_id: str, customer_email: str
    ) -> Appointment | None: ...

    async def customer_appointments(self, customer_email: str, limit: int) -> list[Appointment]:
        """Citas del cliente por `starts_at`, de la más lejana a la más antigua
        (las próximas, primero)."""
        ...


class TicketSource(Protocol):
    async def customer_tickets(self, customer_email: str, limit: int) -> list[Ticket]:
        """Tickets del cliente, del más reciente al más antiguo."""
        ...

    async def create_ticket(self, ticket: NewTicket) -> CreatedTicket:
        """Abre un ticket. Con una `idempotency_key` ya usada devuelve el ticket
        que se creó entonces (`created=False`), no otro."""
        ...


@dataclass(frozen=True, slots=True)
class TenantIntegrations:
    """Lo que tiene conectado una empresa. `None` = no tiene ese sistema: sus
    herramientas no están disponibles y la IA no las ve."""

    orders: OrderSource | None = None
    invoices: InvoiceSource | None = None
    tickets: TicketSource | None = None
    appointments: AppointmentSource | None = None


NO_INTEGRATIONS = TenantIntegrations()


class IntegrationResolver(Protocol):
    async def for_tenant(self, tenant_id: int) -> TenantIntegrations: ...
