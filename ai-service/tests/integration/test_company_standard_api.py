"""El sistema propio de una empresa (API estándar con su URL y su clave):
configuración desde Laravel y anti-SSRF del servidor MCP (`HostGuard`)."""

import logging
from typing import Any

import httpx
import pytest

from ai_service.mcp_server.config import McpSettings
from ai_service.mcp_server.integrations.contracts import IntegrationError
from ai_service.mcp_server.integrations.laravel import LaravelIntegrations
from ai_service.mcp_server.integrations.standard_api import HostGuard
from ai_service.mcp_server.tools import BusinessTools
from tests.support.contracts import CONTRACTS, load
from tests.support.mcp import demo_integrations

LARAVEL = "http://laravel.test"
DATA_TOKEN = "token-de-datos"
ERP = "https://erp.empresa.test/aimail"
ERP_KEY = "clave-secreta-del-erp"
EXAMPLES = CONTRACTS.parent / "integrations" / "examples" / "valid"
ORDER: dict[str, Any] = load(EXAMPLES / "order--full.json")
INVOICE: dict[str, Any] = load(EXAMPLES / "invoice--paid.json")
CLIENTE = "cliente@cliente.test"


def laravel_and_erp(config: dict[str, Any], seen: list[httpx.Request]) -> httpx.MockTransport:
    def handler(request: httpx.Request) -> httpx.Response:
        seen.append(request)
        if request.url.host == "laravel.test":
            return httpx.Response(200, json={"tenant_id": 8, **config})
        if request.headers.get("Authorization") != f"Bearer {ERP_KEY}":
            return httpx.Response(401)
        if request.url.path == "/aimail/v1/orders/45821":
            return httpx.Response(200, json=ORDER)
        if request.url.path == "/aimail/v1/invoices/F2026-0042":
            return httpx.Response(200, json=INVOICE)
        return httpx.Response(404)

    return httpx.MockTransport(handler)


OWN = {"type": "standard_api", "base_url": ERP, "token": ERP_KEY}


def resolver(
    config: dict[str, Any], seen: list[httpx.Request], guard: HostGuard
) -> LaravelIntegrations:
    return LaravelIntegrations(
        LARAVEL,
        DATA_TOKEN,
        fallback=demo_integrations(),
        transport=laravel_and_erp(config, seen),
        guard=guard,
    )


async def test_orders_and_invoices_come_from_the_company_system_with_its_own_key() -> None:
    seen: list[httpx.Request] = []
    # Permitir hosts privados: "erp.empresa.test" no resuelve en los tests.
    tools = BusinessTools(
        resolver({"orders": OWN, "invoices": OWN}, seen, HostGuard(allow_private=True))
    )

    order = await tools.get_order(8, CLIENTE, "45821")
    invoice = await tools.get_invoice(8, CLIENTE, "F2026-0042")

    assert order["shipment"]["carrier"] == "SEUR"
    assert invoice["status"] == "paid"
    erp = [r for r in seen if r.url.host == "erp.empresa.test"]
    assert {r.headers["Authorization"] for r in erp} == {f"Bearer {ERP_KEY}"}
    # La clave de datos de Laravel nunca viaja al sistema de la empresa.
    assert all(DATA_TOKEN not in r.headers["Authorization"] for r in erp)


async def test_only_what_the_company_system_implements_is_available() -> None:
    tools = BusinessTools(
        resolver({"orders": OWN, "invoices": None}, [], HostGuard(allow_private=True))
    )

    availability = await tools.availability(8)

    assert availability["get_order"] is True
    assert availability["get_invoice"] is False


@pytest.mark.parametrize(
    ("base_url", "allow_private", "reason"),
    [
        ("https://169.254.169.254/latest", True, "dirección interna"),  # metadatos: nunca
        ("https://[::ffff:169.254.169.254]/", True, "dirección interna"),
        ("https://0.0.0.0/", True, "dirección interna"),
        ("https://127.0.0.1/", False, "dirección interna"),
        ("https://10.0.0.5/", False, "dirección interna"),
        ("http://93.184.215.14/", False, "https://"),
    ],
)
async def test_a_company_url_pointing_inside_is_refused_before_any_request(
    base_url: str, allow_private: bool, reason: str
) -> None:
    seen: list[httpx.Request] = []
    source = {"type": "standard_api", "base_url": base_url, "token": ERP_KEY}
    tools = BusinessTools(
        resolver({"orders": source}, seen, HostGuard(allow_private=allow_private))
    )

    with pytest.raises(IntegrationError, match=reason):
        await tools.get_order(8, CLIENTE, "45821")

    assert all(r.url.host == "laravel.test" for r in seen)  # al sistema, nada


async def test_the_guard_allows_public_https_addresses() -> None:
    assert await HostGuard().reject("https://93.184.215.14/aimail") is None


async def test_private_hosts_only_count_in_local(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("AI_SERVICE_MCP_ALLOW_PRIVATE_HOSTS", "true")

    monkeypatch.setenv("AI_SERVICE_ENVIRONMENT", "production")
    assert McpSettings(_env_file=None).private_hosts_allowed is False
    monkeypatch.setenv("AI_SERVICE_ENVIRONMENT", "local")
    assert McpSettings(_env_file=None).private_hosts_allowed is True


async def test_the_company_key_never_reaches_the_logs(caplog: pytest.LogCaptureFixture) -> None:
    caplog.set_level(logging.DEBUG)

    def broken(request: httpx.Request) -> httpx.Response:
        # Configuración que no valida (el modelo de Pydantic la rechaza) con la clave dentro.
        return httpx.Response(200, json={"orders": {"type": 42, "token": ERP_KEY}})

    integrations = LaravelIntegrations(LARAVEL, DATA_TOKEN, transport=httpx.MockTransport(broken))
    with pytest.raises(IntegrationError):
        await integrations.for_tenant(8)

    tools = BusinessTools(resolver({"orders": OWN}, [], HostGuard(allow_private=True)))
    with pytest.raises(IntegrationError):
        # El ERP responde 404 a la lista: error registrado en el log (host y ruta).
        await tools.get_customer_orders(8, CLIENTE, 5)
    assert "erp.empresa.test" in caplog.text
    assert ERP_KEY not in caplog.text
