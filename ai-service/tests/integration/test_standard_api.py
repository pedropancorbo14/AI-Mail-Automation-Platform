"""Proveedor de la API estándar contra una API simulada (httpx.MockTransport)."""

import json
import logging
from collections.abc import Callable
from typing import Any

import httpx
import pytest

from ai_service.mcp_server.integrations.contracts import IntegrationError, TenantIntegrations
from ai_service.mcp_server.integrations.standard_api import (
    StandardApiClient,
    StandardApiInvoices,
    StandardApiOrders,
    standard_api_integrations,
)
from ai_service.mcp_server.tools import NOT_FOUND, BusinessTools, ToolInputError
from tests.support.contracts import CONTRACTS, load

BASE = "https://erp.empresa.test/aimail"
TOKEN = "clave-secreta-de-la-empresa"
EXAMPLES = CONTRACTS.parent / "integrations" / "examples" / "valid"
ORDER: dict[str, Any] = load(EXAMPLES / "order--full.json")
CLIENTE = "cliente@cliente.test"

Handler = Callable[[httpx.Request], httpx.Response]


def client(handler: Handler, **kwargs: Any) -> StandardApiClient:
    return StandardApiClient(BASE, TOKEN, transport=httpx.MockTransport(handler), **kwargs)


def orders(handler: Handler, **kwargs: Any) -> StandardApiOrders:
    return StandardApiOrders(client(handler, **kwargs))


async def test_gets_an_order_with_the_token_and_the_customer() -> None:
    seen: list[httpx.Request] = []

    def handler(request: httpx.Request) -> httpx.Response:
        seen.append(request)
        return httpx.Response(200, json=ORDER)

    order = await orders(handler).find_order("ES/1 2", CLIENTE)

    assert order is not None
    assert order.number == "45821"
    assert order.items[0].name == "Lámpara de mesa Nórdica"
    assert order.shipment is not None
    assert order.shipment.tracking_number == "SEU123456789ES"
    assert order.shipment.shipped_at == "2026-09-29T17:40:00+02:00"
    request = seen[0]
    # El número va codificado: "/" no puede cambiar la ruta.
    assert (
        request.url.raw_path
        == b"/aimail/v1/orders/ES%2F1%202?customer_email=cliente%40cliente.test"
    )
    assert request.headers["Authorization"] == f"Bearer {TOKEN}"
    assert request.headers["Accept"] == "application/json"


async def test_lists_orders_with_the_limit_bounded() -> None:
    seen: list[httpx.Request] = []

    def handler(request: httpx.Request) -> httpx.Response:
        seen.append(request)
        return httpx.Response(200, json={"data": [ORDER, {**ORDER, "number": "44102"}]})

    found = await orders(handler).customer_orders(CLIENTE, 500)

    assert [o.number for o in found] == ["45821", "44102"]
    assert seen[0].url.params["limit"] == "100"
    assert seen[0].url.params["customer_email"] == CLIENTE


async def test_a_404_of_an_order_is_not_found() -> None:
    assert await orders(lambda _: httpx.Response(404)).find_order("1", CLIENTE) is None


async def test_invoices_use_their_own_endpoints() -> None:
    invoice = load(EXAMPLES / "invoice--paid.json")
    paths: list[str] = []

    def handler(request: httpx.Request) -> httpx.Response:
        paths.append(request.url.path)
        if request.url.path.endswith("/invoices"):
            return httpx.Response(200, json={"data": [invoice]})
        return httpx.Response(200, json=invoice)

    source = StandardApiInvoices(client(handler))
    one = await source.find_invoice("F2026-0042", CLIENTE)
    listed = await source.customer_invoices(CLIENTE, 5)

    assert one is not None
    assert one.status == "paid"
    assert [i.number for i in listed] == ["F2026-0042"]
    assert paths == ["/aimail/v1/invoices/F2026-0042", "/aimail/v1/invoices"]


def raises(exc: type[Exception]) -> Handler:
    def handler(request: httpx.Request) -> httpx.Response:
        raise exc("boom", request=request)  # type: ignore[call-arg]

    return handler


@pytest.mark.parametrize(
    ("handler", "message"),
    [
        (lambda _: httpx.Response(401), "rechazó las credenciales"),
        (lambda _: httpx.Response(403), "rechazó las credenciales"),
        (lambda _: httpx.Response(429), "no responde"),
        (lambda _: httpx.Response(503), "no responde"),
        (raises(httpx.ConnectError), "no responde"),
        (raises(httpx.ReadTimeout), "no responde"),
        (
            lambda _: httpx.Response(302, headers={"Location": "http://169.254.169.254/"}),
            "respuesta no válida",
        ),
        (lambda _: httpx.Response(400), "respuesta no válida"),
        (lambda _: httpx.Response(200, text="<html>no es JSON</html>"), "respuesta no válida"),
        (
            lambda _: httpx.Response(200, json={**ORDER, "status": "on_the_way"}),
            "respuesta no válida",
        ),
        (lambda _: httpx.Response(200, json={**ORDER, "total": "89.90"}), "respuesta no válida"),
        (
            lambda _: httpx.Response(200, json={**ORDER, "placed_at": 1700000000}),
            "respuesta no válida",
        ),
    ],
    ids=[
        "401",
        "403",
        "429",
        "503",
        "connection",
        "timeout",
        "redirect-not-followed",
        "400",
        "not-json",
        "unknown-status",
        "total-as-text",
        "date-as-number",
    ],
)
async def test_every_failure_is_an_integration_error_never_not_found(
    handler: Handler, message: str
) -> None:
    with pytest.raises(IntegrationError, match=message) as error:
        await orders(handler).find_order("45821", CLIENTE)

    assert "pedidos" in str(error.value)
    assert TOKEN not in str(error.value)


async def test_a_404_of_a_list_means_the_api_is_not_implemented() -> None:
    with pytest.raises(IntegrationError, match="respuesta no válida"):
        await orders(lambda _: httpx.Response(404)).customer_orders(CLIENTE, 5)


async def test_a_response_bigger_than_the_maximum_is_cut() -> None:
    big = {"data": [ORDER] * 100}

    with pytest.raises(IntegrationError, match="respuesta no válida"):
        await orders(lambda _: httpx.Response(200, json=big), max_bytes=10_000).customer_orders(
            CLIENTE, 100
        )


async def test_the_token_never_reaches_the_logs(caplog: pytest.LogCaptureFixture) -> None:
    caplog.set_level(logging.WARNING)

    with pytest.raises(IntegrationError):
        await orders(lambda _: httpx.Response(401)).find_order("45821", CLIENTE)

    assert "erp.empresa.test" in caplog.text
    assert TOKEN not in caplog.text


@pytest.mark.parametrize(
    ("orders_enabled", "invoices_enabled"), [(True, True), (True, False), (False, True)]
)
def test_a_company_can_implement_only_part_of_the_api(
    orders_enabled: bool, invoices_enabled: bool
) -> None:
    found = standard_api_integrations(
        client(lambda _: httpx.Response(200)), orders=orders_enabled, invoices=invoices_enabled
    )

    assert (found.orders is not None) is orders_enabled
    assert (found.invoices is not None) is invoices_enabled
    assert found.tickets is None


class Resolver:
    def __init__(self, integrations: TenantIntegrations) -> None:
        self._integrations = integrations

    async def for_tenant(self, tenant_id: int) -> TenantIntegrations:
        return self._integrations


async def test_through_business_tools_the_ai_only_sees_its_own_customer() -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        # Una API mal implementada que no filtra por cliente.
        if request.url.path.endswith("/v1/orders"):
            other = {**ORDER, "number": "76540", "customer_email": "marcos@cliente.test"}
            return httpx.Response(200, json={"data": [ORDER, other]})
        return httpx.Response(200, json={**ORDER, "customer_email": "marcos@cliente.test"})

    tools = BusinessTools(Resolver(standard_api_integrations(client(handler))))

    listed = await tools.get_customer_orders(1, CLIENTE, 10)
    assert [o["order_number"] for o in listed["orders"]] == ["45821"]
    with pytest.raises(ToolInputError, match=NOT_FOUND):
        await tools.get_order(1, CLIENTE, "45821")
    presented = json.dumps(listed)
    assert "marcos" not in presented
