"""Resolvedor de integraciones que pregunta a Laravel (API simulada)."""

from typing import Any

import httpx
import pytest

from ai_service.entrypoints.mcp import build_resolver
from ai_service.mcp_server.config import McpSettings
from ai_service.mcp_server.integrations.contracts import IntegrationError
from ai_service.mcp_server.integrations.demo import DemoIntegrations
from ai_service.mcp_server.integrations.laravel import LaravelIntegrations
from ai_service.mcp_server.tools import NOT_FOUND, BusinessTools, ToolInputError
from tests.support.contracts import CONTRACTS, load
from tests.support.mcp import demo_integrations

LARAVEL = "http://laravel.test"
TOKEN = "token-de-datos"
ORDER: dict[str, Any] = load(
    CONTRACTS.parent / "integrations" / "examples" / "valid" / "order--full.json"
)
CLIENTE = "cliente@cliente.test"


class FakeLaravel:
    """Empresa 7: pedidos por CSV. El resto: nada configurado."""

    def __init__(self) -> None:
        self.requests: list[httpx.Request] = []
        self.down = False
        # Slug de cada empresa (Laravel lo manda; sin él, la demo usa el id).
        self.slugs: dict[int, str] = {}

    def __call__(self, request: httpx.Request) -> httpx.Response:
        self.requests.append(request)
        if self.down:
            raise httpx.ConnectError("caído", request=request)
        if request.headers.get("Authorization") != f"Bearer {TOKEN}":
            return httpx.Response(404)
        path = request.url.path
        if path.startswith("/internal/integrations/"):
            tenant = int(path.rsplit("/", 1)[1])
            orders = {"type": "csv"} if tenant == 7 else None
            slug = self.slugs.get(tenant)
            return httpx.Response(
                200,
                json={"tenant_id": tenant, "tenant_slug": slug, "orders": orders, "invoices": None},
            )
        if path == "/internal/standard-api/7/v1/orders/45821":
            return httpx.Response(200, json=ORDER)
        if path == "/internal/standard-api/7/v1/orders":
            return httpx.Response(200, json={"data": [ORDER]})
        return httpx.Response(404)

    def paths(self) -> list[str]:
        return [r.url.path for r in self.requests]


class Clock:
    def __init__(self) -> None:
        self.now = 0.0

    def __call__(self) -> float:
        return self.now


def resolver(
    laravel: FakeLaravel, clock: Clock | None = None, *, fallback: bool = True
) -> LaravelIntegrations:
    return LaravelIntegrations(
        LARAVEL,
        TOKEN,
        fallback=demo_integrations() if fallback else None,
        transport=httpx.MockTransport(laravel),
        clock=clock or Clock(),
    )


async def test_a_company_with_csv_orders_reads_them_from_laravel_standard_api() -> None:
    laravel = FakeLaravel()
    tools = BusinessTools(resolver(laravel))

    order = await tools.get_order(7, CLIENTE, "#45821")
    availability = await tools.availability(7)

    assert order["shipment"]["tracking_number"] == "SEU123456789ES"
    assert "/internal/standard-api/7/v1/orders/45821" in laravel.paths()
    # Solo pedidos por CSV: las facturas y los tickets no están disponibles.
    assert availability["get_order"] is True
    assert availability["get_invoice"] is False
    assert availability["get_customer_tickets"] is False


async def test_a_company_without_anything_in_laravel_falls_back_to_the_demo_data() -> None:
    tools = BusinessTools(resolver(FakeLaravel()))

    order = await tools.get_order(1, CLIENTE, "45821")  # Empresa Demo

    assert order["status"] == "shipped"
    with pytest.raises(ToolInputError, match=NOT_FOUND):
        await tools.get_order(99, CLIENTE, "45821")  # ni en Laravel ni en la demo


async def test_the_configuration_is_cached_per_company() -> None:
    laravel, clock = FakeLaravel(), Clock()
    integrations = resolver(laravel, clock)

    await integrations.for_tenant(7)
    await integrations.for_tenant(7)
    clock.now = 61
    await integrations.for_tenant(7)

    assert laravel.paths().count("/internal/integrations/7") == 2


async def test_if_laravel_goes_down_the_last_known_configuration_is_used() -> None:
    laravel, clock = FakeLaravel(), Clock()
    integrations = resolver(laravel, clock)
    await integrations.for_tenant(7)

    laravel.down = True
    clock.now = 120
    found = await integrations.for_tenant(7)

    assert found.orders is not None


async def test_without_any_known_configuration_it_is_a_readable_error_not_no_data() -> None:
    laravel = FakeLaravel()
    laravel.down = True
    tools = BusinessTools(resolver(laravel))

    with pytest.raises(IntegrationError, match="No se pudo consultar"):
        await tools.get_order(7, CLIENTE, "45821")


async def test_a_wrong_token_is_an_error_too() -> None:
    integrations = LaravelIntegrations(
        LARAVEL, "otro", transport=httpx.MockTransport(FakeLaravel())
    )

    with pytest.raises(IntegrationError):
        await integrations.for_tenant(7)


async def test_an_unknown_kind_of_integration_is_ignored() -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(200, json={"orders": {"type": "shopify"}})

    integrations = LaravelIntegrations(
        LARAVEL, TOKEN, transport=httpx.MockTransport(handler), fallback=None
    )

    assert (await integrations.for_tenant(7)).orders is None


def test_without_a_laravel_url_only_the_demo_data_is_used() -> None:
    assert isinstance(build_resolver(McpSettings(_env_file=None)), DemoIntegrations)


def test_with_a_laravel_url_the_token_is_required() -> None:
    with pytest.raises(ValueError, match="token"):
        build_resolver(McpSettings(_env_file=None, laravel_url=LARAVEL))

    built = build_resolver(
        McpSettings(_env_file=None, laravel_url=LARAVEL, laravel_token=TOKEN)  # type: ignore[arg-type]
    )
    assert isinstance(built, LaravelIntegrations)


async def test_the_demo_data_is_found_by_the_company_slug_without_configuring_ids() -> None:
    laravel = FakeLaravel()
    laravel.slugs = {12: "clinica-demo", 1: "otra-cosa"}
    tools = BusinessTools(resolver(laravel))

    clinic = await tools.availability(12)  # ningún id configurado: basta el slug
    renamed = await tools.availability(1)  # el id 1 ya no es Empresa Demo

    assert clinic["get_customer_appointments"] is True
    assert clinic["get_order"] is False
    listed = await tools.get_customer_appointments(12, "maria@cliente.test", 5)
    assert [a["appointment_id"] for a in listed["appointments"]] == ["C-2031", "C-1877"]
    # Un slug que no está en los datos de demostración: nada, aunque el id coincida.
    assert set(renamed.values()) == {False}
