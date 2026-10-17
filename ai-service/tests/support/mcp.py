"""Datos de demostración del servidor MCP para los tests (los de Laravel)."""

from pathlib import Path

from ai_service.mcp_server.backends import DemoData

ROOT = Path(__file__).parents[3]
ORDERS = ROOT / "database" / "seeders" / "demo" / "orders.json"
TICKETS = ROOT / "ai-service" / "src" / "ai_service" / "mcp_server" / "data" / "tickets.json"


def demo_data() -> DemoData:
    # Empresa 1 = "Empresa Demo"; la 2 existe pero no tiene datos.
    return DemoData.load(ORDERS, TICKETS, {1: "empresa-demo", 2: "otra-empresa"})
