"""Herramientas que el modelo puede pedir al redactar (Fase 5).

Descritas aquí, en el código, y no copiadas del servidor MCP: así el modelo
nunca ve parámetros como `tenant_id` o `customer_email` (los pone el backend) y
la descripción está escrita para él. Solo herramientas de LECTURA: las que
modifican datos solo las propone el backend y las aprueba una persona.

Al modelo solo se le ofrecen las de los especialistas que intervienen en el
email (más la búsqueda en la documentación, si hay RAG): una queja no puede
acabar consultando facturas si nadie de facturación interviene.

Esquemas compatibles con el modo estricto (todo `required`,
`additionalProperties: false`, sin `minimum`/`maximum`: los límites se acotan
en el servidor MCP).
"""

from collections.abc import Iterable

from ai_service.application.dto import ToolSpec
from ai_service.application.services.tool_planner import READ_TOOLS
from ai_service.domain.specialist import Specialist

SEARCH_TOOL = "search_company_documents"

# Activadas para una empresa que no ha configurado nada (contracts/tools/catalog.json,
# un test comprueba que coinciden): solo la documentación, nunca datos de clientes.
DEFAULT_ENABLED_TOOLS = frozenset({SEARCH_TOOL})


def _schema(properties: dict[str, dict[str, str]]) -> dict[str, object]:
    return {
        "type": "object",
        "properties": properties,
        "required": list(properties),
        "additionalProperties": False,
    }


_LIMIT = {"limit": {"type": "integer", "description": "Cuántos, de 1 a 10."}}

SPECS: dict[str, ToolSpec] = {
    "get_order": ToolSpec(
        "get_order",
        "Detalle de un pedido del cliente que escribe: estado, productos, envío, "
        "transportista y seguimiento. Un pedido de otro cliente no se encuentra.",
        _schema({"order_number": {"type": "string", "description": "Número del pedido."}}),
    ),
    "get_customer_orders": ToolSpec(
        "get_customer_orders",
        "Pedidos del cliente que escribe (resumen), del más reciente al más antiguo. "
        "Úsalo para saber a qué pedido se refiere si no da el número.",
        _schema(_LIMIT),
    ),
    "get_invoice": ToolSpec(
        "get_invoice",
        "Detalle de una factura del cliente que escribe: número, pedido, fecha, "
        "importe y estado de pago.",
        _schema({"invoice_number": {"type": "string", "description": "Número de factura."}}),
    ),
    "get_customer_invoices": ToolSpec(
        "get_customer_invoices",
        "Facturas del cliente que escribe, de la más reciente a la más antigua.",
        _schema(_LIMIT),
    ),
    "get_customer_tickets": ToolSpec(
        "get_customer_tickets",
        "Tickets de soporte del cliente que escribe, con su estado y última novedad.",
        _schema(_LIMIT),
    ),
    "get_appointment": ToolSpec(
        "get_appointment",
        "Detalle de una cita del cliente que escribe: fecha y hora, servicio, profesional, "
        "lugar e indicaciones. Una cita de otro cliente no se encuentra.",
        _schema({"appointment_id": {"type": "string", "description": "Identificador de la cita."}}),
    ),
    "get_customer_appointments": ToolSpec(
        "get_customer_appointments",
        "Citas del cliente que escribe, las próximas primero (upcoming = aún no ha "
        "llegado). Úsalo para saber a qué cita se refiere si no da el identificador.",
        _schema(_LIMIT),
    ),
    "get_customer": ToolSpec(
        "get_customer",
        "Datos del cliente que escribe: nombre, número de pedidos y fechas.",
        _schema({}),
    ),
    SEARCH_TOOL: ToolSpec(
        SEARCH_TOOL,
        "Busca en la documentación de la empresa (políticas, FAQs, manuales) con una "
        "pregunta concreta, si lo que ya tienes en <documentacion> no cubre algo.",
        _schema({"query": {"type": "string", "description": "Pregunta a buscar."}}),
    ),
}


def tools_for_model(
    specialists: Iterable[Specialist], available: frozenset[str], *, search: bool
) -> tuple[ToolSpec, ...]:
    """Herramientas de los especialistas del email que existen en el servidor, en
    orden estable (el conjunto no cambia durante la conversación)."""
    names = {tool for s in specialists for tool in READ_TOOLS[s] if tool in available}
    if search:
        names.add(SEARCH_TOOL)
    return tuple(SPECS[name] for name in sorted(names) if name in SPECS)
