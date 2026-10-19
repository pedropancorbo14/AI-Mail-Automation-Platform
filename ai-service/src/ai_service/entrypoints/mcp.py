"""`ai-service-mcp`: arranca el servidor MCP (streamable HTTP en /mcp)."""

import uvicorn

from ai_service.infrastructure.observability.logging import configure_logging, get_logger
from ai_service.mcp_server.config import McpSettings
from ai_service.mcp_server.integrations.contracts import IntegrationResolver
from ai_service.mcp_server.integrations.demo import DemoIntegrations
from ai_service.mcp_server.integrations.laravel import LaravelIntegrations
from ai_service.mcp_server.server import build_app
from ai_service.mcp_server.tools import BusinessTools


def build_resolver(settings: McpSettings) -> IntegrationResolver:
    """Laravel (lo que importó o conectó cada empresa) y, si una empresa no
    tiene nada, los datos de demostración."""
    demo = DemoIntegrations.load(settings.orders_file, settings.tickets_file, settings.demo_tenants)
    if not settings.laravel_url:
        return demo
    token = settings.laravel_token.get_secret_value() if settings.laravel_token else ""
    return LaravelIntegrations(
        settings.laravel_url,
        token,
        fallback=demo,
        ttl_seconds=settings.integrations_cache_seconds,
    )


def main() -> None:
    settings = McpSettings()
    configure_logging("INFO")
    resolver = build_resolver(settings)
    token = settings.token.get_secret_value() if settings.token else ""
    app = build_app(BusinessTools(resolver), token)
    get_logger(__name__).info(
        "mcp_starting", port=settings.port, laravel=bool(settings.laravel_url)
    )
    uvicorn.run(app, host=settings.host, port=settings.port, log_config=None, access_log=False)
