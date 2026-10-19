"""Proveedor de demostración: pedidos y tickets de ficheros JSON.

Los pedidos son los de "Empresa Demo", coherentes con los emails de prueba;
las facturas se derivan de ellos (una `FAC-<pedido>` por pedido facturado) y
los tickets creados se guardan en memoria (se pierden al reiniciar).
"""

import json
from dataclasses import dataclass, field
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

from ai_service.mcp_server.integrations.contracts import NO_INTEGRATIONS, TenantIntegrations
from ai_service.mcp_server.integrations.models import (
    CreatedTicket,
    Invoice,
    NewTicket,
    Order,
    OrderItem,
    Shipment,
    Ticket,
)

# Pedidos que todavía no (o nunca) se facturan.
NOT_INVOICED = frozenset({"pending_payment", "cancelled"})


def _same(a: str, b: str) -> bool:
    return a.strip().casefold() == b.strip().casefold()


def _order(row: dict[str, Any]) -> Order:
    shipment = row.get("shipment")
    return Order(
        number=str(row["number"]),
        customer_email=str(row.get("customer_email", "")),
        status=str(row["status"]),
        placed_at=row.get("placed_at"),
        customer_name=row.get("customer_name"),
        total=row.get("total"),
        currency=row.get("currency"),
        items=tuple(
            OrderItem(
                name=str(i.get("name", "")),
                quantity=int(i.get("quantity", 1)),
                unit_price=i.get("unit_price"),
                sku=i.get("sku"),
            )
            for i in row.get("items", [])
        ),
        shipment=Shipment(**shipment) if shipment else None,
        shipping_city=row.get("shipping_city"),
        shipping_country=row.get("shipping_country"),
    )


def _ticket(row: dict[str, Any]) -> Ticket:
    return Ticket(
        id=str(row["id"]),
        customer_email=str(row.get("customer_email", "")),
        status=str(row.get("status", "open")),
        subject=row.get("subject"),
        priority=row.get("priority"),
        created_at=row.get("created_at"),
        last_update=row.get("last_update"),
    )


@dataclass(frozen=True, slots=True)
class DemoOrders:
    orders: tuple[Order, ...]

    def _mine(self, customer_email: str) -> list[Order]:
        mine = [o for o in self.orders if _same(o.customer_email, customer_email)]
        return sorted(mine, key=lambda o: o.placed_at or "", reverse=True)

    async def find_order(self, number: str, customer_email: str) -> Order | None:
        return next((o for o in self.orders if o.number.upper() == number), None)

    async def customer_orders(self, customer_email: str, limit: int) -> list[Order]:
        return self._mine(customer_email)[:limit]


@dataclass(frozen=True, slots=True)
class DemoInvoices:
    orders: DemoOrders

    def _invoices(self, customer_email: str) -> list[Invoice]:
        return [
            Invoice(
                number=f"FAC-{o.number}",
                customer_email=o.customer_email,
                status="refunded" if o.status == "returned" else "paid",
                order_number=o.number,
                issued_at=o.placed_at,
                total=o.total,
                currency=o.currency,
            )
            for o in self.orders._mine(customer_email)
            if o.status not in NOT_INVOICED
        ]

    async def find_invoice(self, number: str, customer_email: str) -> Invoice | None:
        # "44102" (de "factura 44102") también vale: en la demo todas son FAC-<pedido>.
        wanted = number if number.startswith("FAC-") else f"FAC-{number}"
        return next((i for i in self._invoices(customer_email) if i.number == wanted), None)

    async def customer_invoices(self, customer_email: str, limit: int) -> list[Invoice]:
        return self._invoices(customer_email)[:limit]


@dataclass(slots=True)
class DemoTickets:
    tickets: list[Ticket]
    # Tickets creados por clave de idempotencia (compartido entre empresas:
    # la clave ya lleva la empresa).
    created: dict[str, Ticket]
    tenant_id: int

    async def customer_tickets(self, customer_email: str, limit: int) -> list[Ticket]:
        mine = [t for t in self.tickets if _same(t.customer_email, customer_email)]
        return sorted(mine, key=lambda t: t.created_at or "", reverse=True)[:limit]

    async def create_ticket(self, ticket: NewTicket) -> CreatedTicket:
        key = f"{self.tenant_id}:{ticket.idempotency_key}"
        if key in self.created:
            return CreatedTicket(ticket=self.created[key], created=False)
        new = Ticket(
            id=f"TCK-{2000 + len(self.created) + 1}",
            customer_email=ticket.customer_email,
            status="open",
            subject=ticket.subject,
            priority=ticket.priority,
            created_at=datetime.now(UTC).isoformat(),
            last_update="Ticket abierto por el equipo de atención al cliente.",
        )
        self.tickets.append(new)
        self.created[key] = new
        return CreatedTicket(ticket=new, created=True)


@dataclass(slots=True)
class DemoIntegrations:
    """`IntegrationResolver` de demostración. `tenants` = empresa → clave en
    los ficheros. Una empresa conocida tiene helpdesk; pedidos y facturas,
    solo si tiene pedidos en el fichero."""

    tenants: dict[int, str]
    orders: dict[str, tuple[Order, ...]]
    tickets: dict[str, list[Ticket]]
    created: dict[str, Ticket] = field(default_factory=dict)

    @classmethod
    def load(
        cls, orders_file: Path, tickets_file: Path, tenants: dict[int, str]
    ) -> "DemoIntegrations":
        orders = json.loads(orders_file.read_text(encoding="utf-8")) if orders_file.exists() else {}
        tickets = (
            json.loads(tickets_file.read_text(encoding="utf-8")) if tickets_file.exists() else {}
        )
        return cls(
            tenants=dict(tenants),
            orders={slug: tuple(_order(r) for r in rows) for slug, rows in orders.items()},
            tickets={slug: [_ticket(r) for r in rows] for slug, rows in tickets.items()},
        )

    async def for_tenant(self, tenant_id: int) -> TenantIntegrations:
        slug = self.tenants.get(tenant_id)
        if slug is None:
            return NO_INTEGRATIONS
        tickets = DemoTickets(self.tickets.setdefault(slug, []), self.created, tenant_id)
        if not self.orders.get(slug):
            return TenantIntegrations(tickets=tickets)
        orders = DemoOrders(self.orders[slug])
        return TenantIntegrations(orders=orders, invoices=DemoInvoices(orders), tickets=tickets)
