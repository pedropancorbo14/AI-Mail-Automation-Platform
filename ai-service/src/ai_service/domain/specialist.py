"""Especialistas que atienden un email y cómo los elige el supervisor.

Reglas del dominio, sin IA: el especialista principal sale de la intención y
los secundarios de los datos que el clasificador extrajo del email (números de
pedido o de factura). Un email no puede "pedir" un especialista: solo cuentan
la intención y esos datos, así que un texto malicioso no consigue que se
consulten los sistemas de otro dominio.
"""

from enum import StrEnum

from ai_service.domain.category import Category
from ai_service.domain.classification import Classification


class Specialist(StrEnum):
    ORDERS = "orders"  # pedidos, devoluciones y cancelaciones (ERP)
    BILLING = "billing"  # facturas y cobros
    SUPPORT = "support"  # soporte técnico y quejas (tickets)
    APPOINTMENTS = "appointments"  # citas (clínicas, talleres, academias…)
    GENERAL = "general"  # el resto: preguntas de producto, ventas, cuenta...


_PRIMARY = {
    Category.ORDER_STATUS: Specialist.ORDERS,
    Category.RETURN_REQUEST: Specialist.ORDERS,
    Category.CANCELLATION: Specialist.ORDERS,
    Category.INVOICE_REQUEST: Specialist.BILLING,
    Category.BILLING_ISSUE: Specialist.BILLING,
    Category.TECHNICAL_SUPPORT: Specialist.SUPPORT,
    Category.COMPLAINT: Specialist.SUPPORT,
    Category.APPOINTMENT: Specialist.APPOINTMENTS,
}


def primary_specialist(category: Category) -> Specialist:
    return _PRIMARY.get(category, Specialist.GENERAL)


def route(classification: Classification) -> tuple[Specialist, ...]:
    """Especialistas del email: el principal primero y, después, los secundarios.

    - El email menciona pedidos → también `orders` (el detalle del pedido).
    - El email menciona facturas → también `billing`.
    """
    primary = primary_specialist(classification.category)
    chosen = [primary]
    if classification.order_numbers and Specialist.ORDERS not in chosen:
        chosen.append(Specialist.ORDERS)
    if classification.invoice_numbers and Specialist.BILLING not in chosen:
        chosen.append(Specialist.BILLING)
    return tuple(chosen)
