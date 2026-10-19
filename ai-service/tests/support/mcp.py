"""Datos de demostración del servidor MCP para los tests."""

from pathlib import Path

from ai_service.mcp_server.integrations.demo import DemoIntegrations
from ai_service.mcp_server.tools import BusinessTools

DATA = Path(__file__).parents[2] / "src" / "ai_service" / "mcp_server" / "data"
ORDERS = DATA / "orders.json"
TICKETS = DATA / "tickets.json"


def demo_integrations() -> DemoIntegrations:
    # Empresa 1 = "Empresa Demo"; la 2 existe pero no tiene pedidos (sí helpdesk).
    return DemoIntegrations.load(ORDERS, TICKETS, {1: "empresa-demo", 2: "otra-empresa"})


def demo_tools() -> BusinessTools:
    return BusinessTools(demo_integrations())
