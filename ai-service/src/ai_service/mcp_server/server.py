"""Servidor MCP con las herramientas de CRM, ERP y tickets (SDK oficial, 2.x).

Las herramientas son finas: delegan en `BusinessTools` (reglas comunes) y
este en el proveedor de datos de cada empresa (`integrations/`).

Transporte **streamable HTTP** (un contenedor propio, escalable y con su
propia sonda), protegido con un token (`Authorization: Bearer`): solo
ai-service puede llamarlo.

Cada herramienta recibe `tenant_id` y `customer_email`. **No los elige el
modelo**: los pone el cliente MCP de ai-service a partir del email que se
procesa (empresa del evento y remitente). Las herramientas de escritura se
marcan como destructivas (`destructive_hint`): ai-service solo las ejecuta
cuando una persona aprueba la acción en el panel (Fase 4), con una clave de
idempotencia.
"""

import hmac
from collections.abc import Awaitable, Callable, MutableMapping
from typing import Any

from mcp.server.mcpserver import MCPServer
from mcp.server.mcpserver.exceptions import ToolError
from mcp.types import ToolAnnotations
from starlette.applications import Starlette
from starlette.requests import Request
from starlette.responses import JSONResponse

from ai_service.mcp_server.integrations.contracts import IntegrationError
from ai_service.mcp_server.tools import BusinessTools, ToolInputError

Scope = MutableMapping[str, Any]
Message = MutableMapping[str, Any]
Receive = Callable[[], Awaitable[Message]]
Send = Callable[[Message], Awaitable[None]]
ASGIApp = Callable[[Scope, Receive, Send], Awaitable[None]]

READ_ONLY = ToolAnnotations(read_only_hint=True, destructive_hint=False, open_world_hint=False)
# Fallos previstos (pedido no encontrado, sistema de la empresa caído): con
# ToolError el cliente recibe el mensaje tal cual; cualquier otra excepción
# llega como "Error executing tool", sin detalle.
EXPECTED = (ToolInputError, IntegrationError)


def build_server(tools: BusinessTools) -> MCPServer:
    server = MCPServer(
        "ai-mail-business-tools",
        instructions=(
            "Datos de CRM, ERP, facturación y tickets de la empresa, siempre del cliente indicado."
        ),
    )

    @server.tool(
        description="Datos del cliente que escribe: nombre, número de pedidos y fechas.",
        annotations=READ_ONLY,
    )
    async def get_customer(tenant_id: int, customer_email: str) -> dict[str, Any]:
        try:
            return await tools.get_customer(tenant_id, customer_email)
        except EXPECTED as error:
            raise ToolError(str(error)) from error

    @server.tool(
        description=(
            "Detalle de un pedido del cliente que escribe: estado, fechas, productos, "
            "envío y seguimiento. Un pedido de otro cliente no se encuentra."
        ),
        annotations=READ_ONLY,
    )
    async def get_order(tenant_id: int, customer_email: str, order_number: str) -> dict[str, Any]:
        try:
            return await tools.get_order(tenant_id, customer_email, order_number)
        except EXPECTED as error:
            raise ToolError(str(error)) from error

    @server.tool(
        description="Pedidos del cliente que escribe, del más reciente al más antiguo.",
        annotations=READ_ONLY,
    )
    async def get_customer_orders(
        tenant_id: int, customer_email: str, limit: int = 5
    ) -> dict[str, Any]:
        try:
            return await tools.get_customer_orders(tenant_id, customer_email, limit)
        except EXPECTED as error:
            raise ToolError(str(error)) from error

    @server.tool(
        description=(
            "Detalle de una factura del cliente que escribe: número, pedido, fecha, "
            "importe y estado de pago. Una factura de otro cliente no se encuentra."
        ),
        annotations=READ_ONLY,
    )
    async def get_invoice(
        tenant_id: int, customer_email: str, invoice_number: str
    ) -> dict[str, Any]:
        try:
            return await tools.get_invoice(tenant_id, customer_email, invoice_number)
        except EXPECTED as error:
            raise ToolError(str(error)) from error

    @server.tool(
        description="Facturas del cliente que escribe, de la más reciente a la más antigua.",
        annotations=READ_ONLY,
    )
    async def get_customer_invoices(
        tenant_id: int, customer_email: str, limit: int = 5
    ) -> dict[str, Any]:
        try:
            return await tools.get_customer_invoices(tenant_id, customer_email, limit)
        except EXPECTED as error:
            raise ToolError(str(error)) from error

    @server.tool(
        description="Tickets de soporte del cliente que escribe, con su estado.",
        annotations=READ_ONLY,
    )
    async def get_customer_tickets(
        tenant_id: int, customer_email: str, limit: int = 5
    ) -> dict[str, Any]:
        try:
            return await tools.get_customer_tickets(tenant_id, customer_email, limit)
        except EXPECTED as error:
            raise ToolError(str(error)) from error

    @server.tool(
        description="Abre un ticket de soporte para el cliente (MODIFICA datos).",
        annotations=ToolAnnotations(read_only_hint=False, destructive_hint=True),
    )
    async def create_support_ticket(
        tenant_id: int,
        customer_email: str,
        subject: str,
        description: str,
        idempotency_key: str,
        priority: str = "normal",
    ) -> dict[str, Any]:
        try:
            return await tools.create_ticket(
                tenant_id, customer_email, subject, description, priority, idempotency_key
            )
        except EXPECTED as error:
            raise ToolError(str(error)) from error

    return server


class BearerAuth:
    """Middleware ASGI: exige `Authorization: Bearer <token>` (salvo /health)."""

    def __init__(self, app: ASGIApp, token: str) -> None:
        self._app = app
        self._token = token.encode()

    async def __call__(self, scope: Scope, receive: Receive, send: Send) -> None:
        if scope["type"] == "http" and scope.get("path") != "/health":
            headers = dict(scope.get("headers") or [])
            given = headers.get(b"authorization", b"")
            expected = b"Bearer " + self._token
            if not hmac.compare_digest(given, expected):
                response = JSONResponse({"error": "unauthorized"}, status_code=401)
                await response(scope, receive, send)
                return
        await self._app(scope, receive, send)


def build_app(tools: BusinessTools, token: str) -> ASGIApp:
    if not token:
        raise ValueError("Falta AI_SERVICE_MCP_TOKEN: el servidor MCP no arranca sin token.")
    server = build_server(tools)
    app: Starlette = server.streamable_http_app(stateless_http=True, host="0.0.0.0")  # noqa: S104

    async def health(_: Request) -> JSONResponse:
        return JSONResponse({"status": "ok", "service": "ai-mcp"})

    async def capabilities(request: Request) -> JSONResponse:
        """Disponibilidad de cada herramienta para una empresa (con token)."""
        try:
            tenant_id = int(request.query_params.get("tenant_id", ""))
        except ValueError:
            return JSONResponse({"error": "tenant_id no válido"}, status_code=422)
        return JSONResponse({"tenant_id": tenant_id, "tools": await tools.availability(tenant_id)})

    app.add_route("/health", health, methods=["GET"])
    app.add_route("/capabilities", capabilities, methods=["GET"])
    return BearerAuth(app, token)
