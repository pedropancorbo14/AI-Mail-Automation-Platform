"""Acciones que modifican datos que el agente puede PROPONER (nunca ejecutar).

Como `tool_planner`, sin IA: la acción y sus argumentos salen de la intención,
de lo consultado y del propio email. El modelo no elige qué se hace ni con
qué datos, así que un email malicioso no puede conseguir que se cree nada en
nombre de otro cliente o con otro contenido. La acción queda pendiente hasta
que una persona la apruebe en el panel.
"""

import uuid
from collections.abc import Sequence

from ai_service.application.dto import PendingAction, ToolCallRecord
from ai_service.domain.category import Category
from ai_service.domain.classification import Classification, Urgency
from ai_service.domain.email import Email

CREATE_TICKET = "create_support_ticket"
CLOSED_TICKETS = frozenset({"closed", "resolved", "cancelled"})


def plan_action(
    email: Email,
    classification: Classification,
    tool_results: Sequence[ToolCallRecord],
    available: frozenset[str],
) -> PendingAction | None:
    """Soporte técnico de un cliente sin ticket abierto → proponer abrir uno.

    Solo si se pudieron consultar sus tickets: sin ese dato no se sabe si ya
    tiene uno abierto y se crearía un duplicado.
    """
    if CREATE_TICKET not in available or classification.category is not Category.TECHNICAL_SUPPORT:
        return None
    lookup = next(
        (r for r in tool_results if r.tool == "get_customer_tickets" and r.status == "success"),
        None,
    )
    if lookup is None or lookup.result is None:
        return None
    tickets = lookup.result.get("tickets", [])
    if any(isinstance(t, dict) and t.get("status") not in CLOSED_TICKETS for t in tickets):
        return None

    subject = (email.subject.strip() or "Consulta de soporte técnico")[:150]
    description = classification.summary.strip()
    if classification.order_numbers:
        description += " Pedidos mencionados: " + ", ".join(classification.order_numbers) + "."
    priority = "high" if classification.urgency is Urgency.HIGH else "normal"
    label = "alta" if priority == "high" else "normal"
    return PendingAction(
        action_id=str(uuid.uuid4()),
        tool=CREATE_TICKET,
        arguments={"subject": subject, "description": description[:2000], "priority": priority},
        summary=f"Crear un ticket de soporte «{subject}» (prioridad {label}) para {email.sender}."[
            :500
        ],
    )
