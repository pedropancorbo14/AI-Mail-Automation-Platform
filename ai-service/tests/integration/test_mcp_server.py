"""Servidor MCP: herramientas por MCP (en memoria) y por HTTP con token."""

import asyncio
import contextlib
import json
from collections.abc import AsyncIterator
from typing import Any

import httpx
import pytest
import uvicorn
from mcp.client.client import Client

from ai_service.mcp_server.integrations.contracts import IntegrationError, TenantIntegrations
from ai_service.mcp_server.integrations.models import Order
from ai_service.mcp_server.server import build_app, build_server
from ai_service.mcp_server.tools import BusinessTools
from tests.support.mcp import demo_tools

TOKEN = "mcp-test-token"
CONTEXT = {"tenant_id": 1, "customer_email": "cliente@cliente.test"}


async def test_lists_the_business_tools_with_read_only_annotations() -> None:
    async with Client(build_server(demo_tools())) as client:
        tools = {t.name: t for t in (await client.list_tools()).tools}

    assert set(tools) == {
        "get_customer",
        "get_order",
        "get_customer_orders",
        "get_invoice",
        "get_customer_invoices",
        "get_customer_tickets",
        "get_appointment",
        "get_customer_appointments",
        "create_support_ticket",
    }
    assert tools["get_order"].annotations is not None
    assert tools["get_order"].annotations.read_only_hint is True
    assert tools["create_support_ticket"].annotations is not None
    assert tools["create_support_ticket"].annotations.destructive_hint is True


async def test_calls_a_tool_with_the_injected_context() -> None:
    async with Client(build_server(demo_tools())) as client:
        result = await client.call_tool("get_order", {**CONTEXT, "order_number": "#45821"})

    assert not result.is_error
    data: dict[str, Any] = json.loads(result.content[0].text)  # type: ignore[union-attr]
    assert data["order_number"] == "45821"


async def test_another_customers_order_is_an_error_without_data() -> None:
    async with Client(build_server(demo_tools())) as client:
        result = await client.call_tool("get_order", {**CONTEXT, "order_number": "76540"})

    assert result.is_error
    text = result.content[0].text  # type: ignore[union-attr]
    assert "No hay ningún pedido" in text
    assert "marcos" not in text


class DownOrders:
    async def find_order(self, number: str, customer_email: str) -> Order | None:
        raise IntegrationError("El sistema de pedidos de la empresa no responde.")

    async def customer_orders(self, customer_email: str, limit: int) -> list[Order]:
        return []


class DownResolver:
    async def for_tenant(self, tenant_id: int) -> TenantIntegrations:
        return TenantIntegrations(orders=DownOrders())


async def test_a_failing_company_system_reaches_the_ai_as_a_readable_error() -> None:
    async with Client(build_server(BusinessTools(DownResolver()))) as client:
        result = await client.call_tool("get_order", {**CONTEXT, "order_number": "45821"})

    assert result.is_error
    text = result.content[0].text  # type: ignore[union-attr]
    # El SDK antepone "Error executing tool get_order:" (el cliente MCP lo quita).
    assert text.endswith(": El sistema de pedidos de la empresa no responde.")


@contextlib.asynccontextmanager
async def running(port: int, tools: BusinessTools | None = None) -> AsyncIterator[str]:
    config = uvicorn.Config(
        build_app(tools or demo_tools(), TOKEN), host="127.0.0.1", port=port, log_config=None
    )
    server = uvicorn.Server(config)
    task = asyncio.create_task(server.serve())
    for _ in range(50):
        if server.started:
            break
        await asyncio.sleep(0.05)
    try:
        yield f"http://127.0.0.1:{port}"
    finally:
        server.should_exit = True
        await task


async def test_http_requires_the_token_except_for_health() -> None:
    async with running(18110) as url, httpx.AsyncClient() as http:
        assert (await http.get(f"{url}/health")).json() == {"status": "ok", "service": "ai-mcp"}
        assert (await http.post(f"{url}/mcp", json={})).status_code == 401
        bad = await http.post(f"{url}/mcp", json={}, headers={"Authorization": "Bearer otro"})
        assert bad.status_code == 401


def test_the_server_refuses_to_start_without_a_token() -> None:
    with pytest.raises(ValueError, match="AI_SERVICE_MCP_TOKEN"):
        build_app(demo_tools(), "")
