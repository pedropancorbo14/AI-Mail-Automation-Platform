"""Backends de CRM, ERP y tickets. De momento, datos de demostración en JSON.

Cada operación recibe la empresa (`tenant_id`) y el cliente (`customer_email`)
del email que se procesa, y **solo** devuelve datos de ese cliente de esa
empresa. Un pedido de otro cliente se responde igual que uno que no existe:
ni siquiera se puede averiguar si un número existe (mismo criterio que las
herramientas de Laravel). En la Fase 14 de la plataforma estos backends se
sustituyen por Shopify, el ERP o el helpdesk reales sin tocar las tools.
"""

import json
import re
from dataclasses import dataclass, field
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

STATUS_LABELS = {
    "pending_payment": "Pendiente de pago",
    "processing": "En preparación",
    "shipped": "Enviado",
    "delivered": "Entregado",
    "cancelled": "Cancelado",
    "returned": "Devuelto",
}
TICKET_LABELS = {"open": "Abierto", "pending": "Esperando al cliente", "closed": "Cerrado"}
INVOICE_LABELS = {"paid": "Pagada", "refunded": "Abonada (devolución)"}
# Pedidos que todavía no (o nunca) se facturan.
NOT_INVOICED = frozenset({"pending_payment", "cancelled"})

NOT_FOUND = "No hay ningún pedido con ese número asociado a este cliente."
INVOICE_NOT_FOUND = "No hay ninguna factura con ese número asociada a este cliente."


class ToolInputError(ValueError):
    """Error que el modelo debe leer (p. ej. pedido no encontrado)."""


def normalize_order_number(value: str) -> str | None:
    """Primer fragmento con dígitos: "#45821", "nº 45821", "pedido 45821", "ES-1234"."""
    match = re.search(r"[A-Za-z0-9-]*\d[A-Za-z0-9-]*", value)
    return match.group(0).strip("-").upper() if match else None


def _same(a: str, b: str) -> bool:
    return a.strip().casefold() == b.strip().casefold()


PRIORITIES = frozenset({"low", "normal", "high"})


@dataclass(frozen=True, slots=True)
class DemoData:
    orders: dict[str, list[dict[str, Any]]]
    tickets: dict[str, list[dict[str, Any]]]
    tenants: dict[int, str]
    # Tickets creados por clave de idempotencia (demostración: en memoria).
    created: dict[str, dict[str, Any]] = field(default_factory=dict)

    @classmethod
    def load(cls, orders_file: Path, tickets_file: Path, tenants: dict[int, str]) -> "DemoData":
        orders = json.loads(orders_file.read_text(encoding="utf-8")) if orders_file.exists() else {}
        tickets = (
            json.loads(tickets_file.read_text(encoding="utf-8")) if tickets_file.exists() else {}
        )
        return cls(orders=orders, tickets=tickets, tenants=dict(tenants))

    def _orders(self, tenant_id: int, customer: str) -> list[dict[str, Any]]:
        slug = self.tenants.get(tenant_id)
        rows = self.orders.get(slug, []) if slug else []
        mine = [o for o in rows if _same(str(o.get("customer_email", "")), customer)]
        return sorted(mine, key=lambda o: str(o.get("placed_at", "")), reverse=True)

    def get_order(self, tenant_id: int, customer: str, order_number: str) -> dict[str, Any]:
        number = normalize_order_number(order_number)
        for order in self._orders(tenant_id, customer) if number else []:
            if str(order.get("number", "")).upper() == number:
                return present_order(order)
        raise ToolInputError(NOT_FOUND)

    def get_customer_orders(self, tenant_id: int, customer: str, limit: int) -> dict[str, Any]:
        orders = self._orders(tenant_id, customer)[: max(1, min(limit, 10))]
        summary = [
            {
                "order_number": o["number"],
                "status": o["status"],
                "status_label": STATUS_LABELS.get(o["status"], o["status"]),
                "placed_at": o.get("placed_at"),
                "total": o.get("total"),
                "currency": o.get("currency"),
            }
            for o in orders
        ]
        result: dict[str, Any] = {"orders": summary}
        if not summary:
            result["note"] = "Este cliente no tiene pedidos. No inventes ninguno."
        return result

    def _invoices(self, tenant_id: int, customer: str) -> list[dict[str, Any]]:
        """Demostración: una factura por pedido facturado (FAC-<pedido>, por el total)."""
        return [
            present_invoice(o)
            for o in self._orders(tenant_id, customer)
            if o.get("status") not in NOT_INVOICED
        ]

    def get_invoice(self, tenant_id: int, customer: str, invoice_number: str) -> dict[str, Any]:
        number = normalize_order_number(invoice_number)
        if number and not number.startswith("FAC-"):
            number = f"FAC-{number}"
        for invoice in self._invoices(tenant_id, customer) if number else []:
            if invoice["invoice_number"] == number:
                return invoice
        raise ToolInputError(INVOICE_NOT_FOUND)

    def get_customer_invoices(self, tenant_id: int, customer: str, limit: int) -> dict[str, Any]:
        invoices = self._invoices(tenant_id, customer)[: max(1, min(limit, 10))]
        result: dict[str, Any] = {"invoices": invoices}
        if not invoices:
            result["note"] = "Este cliente no tiene facturas. No inventes ninguna."
        return result

    def get_customer(self, tenant_id: int, customer: str) -> dict[str, Any]:
        orders = self._orders(tenant_id, customer)
        if not orders:
            return {"found": False, "note": "No hay ningún cliente con este email en la empresa."}
        return {
            "found": True,
            "name": orders[0].get("customer_name"),
            "email": customer.strip().lower(),
            "orders_count": len(orders),
            "first_order_at": orders[-1].get("placed_at"),
            "last_order_at": orders[0].get("placed_at"),
        }

    def get_customer_tickets(self, tenant_id: int, customer: str, limit: int) -> dict[str, Any]:
        slug = self.tenants.get(tenant_id)
        rows = self.tickets.get(slug, []) if slug else []
        mine = sorted(
            (t for t in rows if _same(str(t.get("customer_email", "")), customer)),
            key=lambda t: str(t.get("created_at", "")),
            reverse=True,
        )[: max(1, min(limit, 10))]
        return {
            "tickets": [
                {
                    "id": t["id"],
                    "subject": t.get("subject"),
                    "status": t.get("status"),
                    "status_label": TICKET_LABELS.get(str(t.get("status")), t.get("status")),
                    "created_at": t.get("created_at"),
                    "last_update": t.get("last_update"),
                }
                for t in mine
            ]
        }

    def availability(self, tenant_id: int) -> dict[str, bool]:
        """Qué herramientas tienen datos para la empresa (el panel lo muestra:
        activar una sin datos no sirve). Pedidos, facturas y ficha salen de los
        pedidos; los tickets, del helpdesk (en la demo, toda empresa conocida)."""
        slug = self.tenants.get(tenant_id)
        has_orders = bool(slug and self.orders.get(slug))
        has_helpdesk = slug is not None
        return {
            "get_order": has_orders,
            "get_customer_orders": has_orders,
            "get_customer": has_orders,
            "get_invoice": has_orders,
            "get_customer_invoices": has_orders,
            "get_customer_tickets": has_helpdesk,
            "create_support_ticket": has_helpdesk,
        }

    def create_ticket(
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
        slug = self.tenants.get(tenant_id)
        if slug is None:
            raise ToolInputError("Esta empresa no tiene un sistema de tickets configurado.")
        if not subject.strip() or len(subject) > 150 or len(description) > 2000:
            raise ToolInputError(
                "Asunto (de 1 a 150 caracteres) o descripción (máx. 2000) no válidos."
            )
        if priority not in PRIORITIES:
            raise ToolInputError("Prioridad no válida (low, normal o high).")
        key = f"{tenant_id}:{idempotency_key}"
        if key in self.created:
            return {"created": False, "ticket": self.created[key]}
        rows = self.tickets.setdefault(slug, [])
        ticket = {
            "id": f"TCK-{2000 + len(self.created) + 1}",
            "customer_email": customer.strip().lower(),
            "subject": subject.strip(),
            "description": description,
            "priority": priority,
            "status": "open",
            "created_at": datetime.now(UTC).isoformat(),
            "last_update": "Ticket abierto por el equipo de atención al cliente.",
        }
        rows.append(ticket)
        visible = {k: v for k, v in ticket.items() if k != "customer_email"}
        self.created[key] = visible
        return {"created": True, "ticket": visible}


def present_invoice(order: dict[str, Any]) -> dict[str, Any]:
    status = "refunded" if order.get("status") == "returned" else "paid"
    return {
        "invoice_number": f"FAC-{order['number']}",
        "order_number": order["number"],
        "issued_at": order.get("placed_at"),
        "total": order.get("total"),
        "currency": order.get("currency"),
        "status": status,
        "status_label": INVOICE_LABELS[status],
    }


def present_order(order: dict[str, Any]) -> dict[str, Any]:
    """Lo que necesita la IA para responder (sin dirección completa)."""
    return {
        "order_number": order["number"],
        "status": order["status"],
        "status_label": STATUS_LABELS.get(order["status"], order["status"]),
        "placed_at": order.get("placed_at"),
        "total": order.get("total"),
        "currency": order.get("currency"),
        "items": order.get("items", []),
        "shipment": order.get("shipment"),
        "shipping_city": order.get("shipping_city"),
        "shipping_country": order.get("shipping_country"),
    }
