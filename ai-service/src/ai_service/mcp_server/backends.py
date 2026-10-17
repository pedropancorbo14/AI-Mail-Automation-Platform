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
from dataclasses import dataclass
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

NOT_FOUND = "No hay ningún pedido con ese número asociado a este cliente."


class ToolInputError(ValueError):
    """Error que el modelo debe leer (p. ej. pedido no encontrado)."""


def normalize_order_number(value: str) -> str | None:
    """Primer fragmento con dígitos: "#45821", "nº 45821", "pedido 45821", "ES-1234"."""
    match = re.search(r"[A-Za-z0-9-]*\d[A-Za-z0-9-]*", value)
    return match.group(0).strip("-").upper() if match else None


def _same(a: str, b: str) -> bool:
    return a.strip().casefold() == b.strip().casefold()


@dataclass(frozen=True, slots=True)
class DemoData:
    orders: dict[str, list[dict[str, Any]]]
    tickets: dict[str, list[dict[str, Any]]]
    tenants: dict[int, str]

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
