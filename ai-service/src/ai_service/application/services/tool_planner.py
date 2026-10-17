"""Qué consultar en los sistemas de la empresa según la intención del email.

Planificación DETERMINISTA (sin IA): predecible, barata y auditable, y un
email malicioso no puede conseguir que se llame a otra herramienta. Los
argumentos salen de la clasificación (números de pedido extraídos del
email) y se validan en el servidor MCP; la empresa y el cliente los pone el
backend. El tool calling decidido por el modelo llegará con los subagentes
(Fase 5).
"""

from dataclasses import dataclass, field
from typing import Any

from ai_service.domain.category import Category
from ai_service.domain.classification import Classification

MAX_ORDERS_PER_EMAIL = 3


@dataclass(frozen=True, slots=True)
class PlannedCall:
    tool: str
    arguments: dict[str, Any] = field(default_factory=dict)


_ORDERS = {Category.ORDER_STATUS, Category.RETURN_REQUEST, Category.CANCELLATION}
_BILLING = {Category.INVOICE_REQUEST, Category.BILLING_ISSUE}
_SUPPORT = {Category.TECHNICAL_SUPPORT, Category.COMPLAINT}


def plan_tool_calls(classification: Classification, available: frozenset[str]) -> list[PlannedCall]:
    category = classification.category
    numbers = list(dict.fromkeys(classification.order_numbers))[:MAX_ORDERS_PER_EMAIL]
    by_number = [PlannedCall("get_order", {"order_number": n}) for n in numbers]
    plan: list[PlannedCall] = []

    if category in _ORDERS:
        plan = by_number or [PlannedCall("get_customer_orders", {"limit": 5})]
    elif category in _BILLING:
        plan = [*by_number, PlannedCall("get_customer_orders", {"limit": 5})]
    elif category in _SUPPORT:
        plan = [*by_number, PlannedCall("get_customer_tickets", {"limit": 5})]
    elif category is Category.ACCOUNT_CHANGE:
        plan = [PlannedCall("get_customer")]

    return [call for call in plan if call.tool in available]


def references_in(tool: str, result: dict[str, Any] | None) -> set[str]:
    """Lo que una respuesta puede citar de un resultado (número de pedido, ticket...)."""
    if not result:
        return set()
    found: set[str] = set()
    if tool == "get_order" and result.get("order_number"):
        found.add(str(result["order_number"]))
    for order in result.get("orders", []) if isinstance(result.get("orders"), list) else []:
        if isinstance(order, dict) and order.get("order_number"):
            found.add(str(order["order_number"]))
    for ticket in result.get("tickets", []) if isinstance(result.get("tickets"), list) else []:
        if isinstance(ticket, dict) and ticket.get("id"):
            found.add(str(ticket["id"]))
    if tool == "get_customer" and result.get("found"):
        found.add(str(result.get("email", "")))
    return found
