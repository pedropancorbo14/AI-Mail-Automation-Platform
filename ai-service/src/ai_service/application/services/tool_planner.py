"""Qué consulta cada especialista en los sistemas de la empresa.

Planificación DETERMINISTA (sin IA): predecible, barata y auditable, y un
email malicioso no puede conseguir que se llame a otra herramienta. Los
argumentos salen de la clasificación (números de pedido o de factura extraídos
del email) y se validan en el servidor MCP; la empresa y el cliente los pone
el backend.

Cada herramienta de lectura es de UN especialista (`READ_TOOLS`): los
especialistas trabajan en paralelo sin repetir consultas. Además del plan, el
modelo puede pedir consultas extra al redactar, pero solo de las herramientas
de los especialistas que intervienen en el email.
"""

from dataclasses import dataclass, field
from typing import Any

from ai_service.domain.category import Category
from ai_service.domain.classification import Classification
from ai_service.domain.specialist import Specialist

MAX_ORDERS_PER_EMAIL = 3
MAX_INVOICES_PER_EMAIL = 3

READ_TOOLS: dict[Specialist, frozenset[str]] = {
    Specialist.ORDERS: frozenset({"get_order", "get_customer_orders"}),
    Specialist.BILLING: frozenset({"get_invoice", "get_customer_invoices"}),
    Specialist.SUPPORT: frozenset({"get_customer_tickets"}),
    Specialist.APPOINTMENTS: frozenset({"get_appointment", "get_customer_appointments"}),
    Specialist.GENERAL: frozenset({"get_customer"}),
}


@dataclass(frozen=True, slots=True)
class PlannedCall:
    tool: str
    arguments: dict[str, Any] = field(default_factory=dict)


def plan_for(
    specialist: Specialist,
    classification: Classification,
    available: frozenset[str],
    *,
    primary: bool,
) -> list[PlannedCall]:
    """Consultas de un especialista. Como secundario solo mira lo que el email
    menciona (ese pedido, esa factura), no todo el historial del cliente."""
    plan: list[PlannedCall] = []
    if specialist is Specialist.ORDERS:
        numbers = _unique(classification.order_numbers, MAX_ORDERS_PER_EMAIL)
        plan = [PlannedCall("get_order", {"order_number": n}) for n in numbers]
        if not plan and primary:
            plan = [PlannedCall("get_customer_orders", {"limit": 5})]
    elif specialist is Specialist.BILLING:
        numbers = _unique(classification.invoice_numbers, MAX_INVOICES_PER_EMAIL)
        plan = [PlannedCall("get_invoice", {"invoice_number": n}) for n in numbers]
        if not plan and primary:
            plan = [PlannedCall("get_customer_invoices", {"limit": 5})]
    elif specialist is Specialist.SUPPORT:
        plan = [PlannedCall("get_customer_tickets", {"limit": 5})]
    elif classification.category is Category.ACCOUNT_CHANGE:
        plan = [PlannedCall("get_customer")]
    return [call for call in plan if call.tool in available]


def references_in(tool: str, result: dict[str, Any] | None) -> set[str]:
    """Lo que una respuesta puede citar de un resultado (pedido, factura, ticket...)."""
    if not result:
        return set()
    found: set[str] = set()
    if tool == "get_order" and result.get("order_number"):
        found.add(str(result["order_number"]))
    if tool == "get_invoice" and result.get("invoice_number"):
        found.add(str(result["invoice_number"]))
    for key, field_name in (("orders", "order_number"), ("invoices", "invoice_number")):
        rows = result.get(key)
        for row in rows if isinstance(rows, list) else []:
            if isinstance(row, dict) and row.get(field_name):
                found.add(str(row[field_name]))
    if tool == "search_company_documents":  # fuentes de la documentación encontrada
        rows = result.get("results")
        for row in rows if isinstance(rows, list) else []:
            if isinstance(row, dict) and row.get("source"):
                found.add(str(row["source"]))
    tickets = result.get("tickets")
    for ticket in tickets if isinstance(tickets, list) else []:
        if isinstance(ticket, dict) and ticket.get("id"):
            found.add(str(ticket["id"]))
    if tool == "get_customer" and result.get("found"):
        found.add(str(result.get("email", "")))
    ticket = result.get("ticket")  # create_support_ticket aprobada y ejecutada
    if isinstance(ticket, dict) and ticket.get("id"):
        found.add(str(ticket["id"]))
    return found


def _unique(values: tuple[str, ...], limit: int) -> list[str]:
    return list(dict.fromkeys(values))[:limit]
