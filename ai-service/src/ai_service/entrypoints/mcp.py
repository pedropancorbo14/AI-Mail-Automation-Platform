"""`ai-service-mcp`: arranca el servidor MCP (streamable HTTP en /mcp)."""

import uvicorn

from ai_service.infrastructure.observability.logging import configure_logging, get_logger
from ai_service.mcp_server.config import McpSettings
from ai_service.mcp_server.integrations.demo import DemoIntegrations
from ai_service.mcp_server.server import build_app
from ai_service.mcp_server.tools import BusinessTools


def main() -> None:
    settings = McpSettings()
    configure_logging("INFO")
    resolver = DemoIntegrations.load(
        settings.orders_file, settings.tickets_file, settings.demo_tenants
    )
    token = settings.token.get_secret_value() if settings.token else ""
    app = build_app(BusinessTools(resolver), token)
    get_logger(__name__).info("mcp_starting", port=settings.port, tenants=len(resolver.tenants))
    uvicorn.run(app, host=settings.host, port=settings.port, log_config=None, access_log=False)
