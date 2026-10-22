"""Proveedor de la API estándar contra una API simulada (httpx.MockTransport)."""

import json
import logging
from collections.abc import Callable
from typing import Any

import httpx
import pytest

from ai_service.mcp_server.integrations.contracts import IntegrationError, TenantIntegrations
from ai_service.mcp_server.integrations.standard_api import (
    StandardApiAppointments,
    StandardApiClient,
    StandardApiInvoices,
    StandardApiOrders,
    standard_api_integrations,
)
from ai_service.mcp_server.tools import (
    APPOINTMENT_NOT_FOUND,
    NOT_FOUND,
    BusinessTools,
    ToolInputError,
)
from tests.support.contracts import CONTRACTS, load

BASE = "https://erp.empresa.test/aimail"
TOKEN = "clave-secreta-de-la-empresa"
EXAMPLES = CONTRACTS.parent / "integrations" / "examples" / "valid"
ORDER: dict[str, Any] = load(EXAMPLES / "order--full.json")
APPOINTMENT: dict[str, Any] = load(EXAMPLES / "appointment--full.json")
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
    assert found.appointments is None  # las citas, solo si la empresa las implementa


def test_a_clinic_implements_only_the_appointments() -> None:
    found = standard_api_integrations(
        client(lambda _: httpx.Response(200)), orders=False, invoices=False, appointments=True
    )

    assert found.orders is None
    assert found.invoices is None
    assert found.appointments is not None


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


# Citas (Fase 14).
MARIA = "maria@cliente.test"


def appointments(handler: Handler) -> StandardApiAppointments:
    return StandardApiAppointments(client(handler))


async def test_gets_an_appointment_with_the_customer_and_the_id_encoded() -> None:
    seen: list[httpx.Request] = []

    def handler(request: httpx.Request) -> httpx.Response:
        seen.append(request)
        return httpx.Response(200, json=APPOINTMENT)

    found = await appointments(handler).find_appointment("C/20 31", MARIA)

    assert found is not None
    assert found.id == "C-2031"
    assert found.starts_at == "2026-10-30T10:30:00+01:00"
    assert found.professional == "Dra. López"
    assert found.price == 45.0
    assert (
        seen[0].url.raw_path
        == b"/aimail/v1/appointments/C%2F20%2031?customer_email=maria%40cliente.test"
    )
    assert seen[0].headers["Authorization"] == f"Bearer {TOKEN}"


async def test_lists_appointments_with_the_limit_bounded() -> None:
    seen: list[httpx.Request] = []

    def handler(request: httpx.Request) -> httpx.Response:
        seen.append(request)
        return httpx.Response(200, json=load(EXAMPLES / "appointment-list--two.json"))

    found = await appointments(handler).customer_appointments(MARIA, 0)

    assert [a.id for a in found] == ["C-2031", "C-1877"]
    assert seen[0].url.path == "/aimail/v1/appointments"
    assert seen[0].url.params["limit"] == "1"


async def test_a_404_of_an_appointment_is_not_found() -> None:
    assert await appointments(lambda _: httpx.Response(404)).find_appointment("1", MARIA) is None


@pytest.mark.parametrize(
    "response",
    [
        httpx.Response(200, json={**APPOINTMENT, "status": "confirmada"}),
        httpx.Response(200, json={**APPOINTMENT, "starts_at": 1793352600}),
        httpx.Response(200, json={k: v for k, v in APPOINTMENT.items() if k != "starts_at"}),
        httpx.Response(503),
    ],
    ids=["estado-desconocido", "fecha-como-numero", "sin-inicio", "caido"],
)
async def test_a_bad_appointment_response_is_an_error_of_the_appointments_system(
    response: httpx.Response,
) -> None:
    with pytest.raises(IntegrationError, match="sistema de citas"):
        await appointments(lambda _: response).find_appointment("C-2031", MARIA)


async def test_through_business_tools_appointments_of_others_never_reach_the_ai() -> None:
    other = {**APPOINTMENT, "id": "C-9", "customer_email": "javier@cliente.test"}

    def handler(request: httpx.Request) -> httpx.Response:
        # Una agenda mal implementada que no filtra por cliente.
        if request.url.path.endswith("/v1/appointments"):
            return httpx.Response(200, json={"data": [APPOINTMENT, other]})
        return httpx.Response(200, json=other)

    tools = BusinessTools(Resolver(standard_api_integrations(client(handler), appointments=True)))

    listed = await tools.get_customer_appointments(1, MARIA, 10)
    assert [a["appointment_id"] for a in listed["appointments"]] == ["C-2031"]
    with pytest.raises(ToolInputError, match=APPOINTMENT_NOT_FOUND):
        await tools.get_appointment(1, MARIA, "C-9")
    assert "javier" not in json.dumps(listed)
