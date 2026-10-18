"""Datos de demostración del servidor MCP para los tests."""

from pathlib import Path

from ai_service.mcp_server.backends import DemoData

DATA = Path(__file__).parents[2] / "src" / "ai_service" / "mcp_server" / "data"
ORDERS = DATA / "orders.json"
TICKETS = DATA / "tickets.json"


def demo_data() -> DemoData:
    # Empresa 1 = "Empresa Demo"; la 2 existe pero no tiene datos.
    return DemoData.load(ORDERS, TICKETS, {1: "empresa-demo", 2: "otra-empresa"})
