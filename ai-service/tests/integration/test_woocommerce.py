"""Proveedor de WooCommerce contra una tienda simulada (respuestas con la forma
real de la API REST v3)."""

import base64
import logging
from typing import Any

import httpx
import pytest

from ai_service.mcp_server.integrations.contracts import IntegrationError
from ai_service.mcp_server.integrations.laravel import LaravelIntegrations
from ai_service.mcp_server.integrations.standard_api import HostGuard
from ai_service.mcp_server.integrations.woocommerce import WooCommerceOrders, woocommerce_client
from ai_service.mcp_server.tools import NOT_FOUND, BusinessTools, ToolInputError

SHOP = "https://tienda.empresa.test"
CK = "ck_0123456789abcdef"
CS = "cs_fedcba9876543210"
CLIENTE = "elena@cliente.test"


def woo_order(number: str | int, email: str = CLIENTE, **overrides: Any) -> dict[str, Any]:
    order: dict[str, Any] = {
        "id": 812,
        "number": number,
        "status": "completed",
        "date_created": "2026-10-15T11:20:00",
        "date_created_gmt": "2026-10-15T09:20:00",
        "total": "245.00",
        "currency": "EUR",
        "billing": {
            "first_name": "Elena",
            "last_name": "Ruiz",
            "email": email,
            "city": "Bilbao",
            "country": "ES",
        },
        "shipping": {"first_name": "Elena", "last_name": "Ruiz", "city": "Getxo", "country": "ES"},
        "line_items": [
            {"id": 1, "name": "Sofá Nórdico", "quantity": 1, "price": 199.0, "sku": "SOF-1"},
            {"id": 2, "name": "Cojín lino", "quantity": 2, "price": 23, "sku": ""},
        ],
        "meta_data": [
            {
                "id": 9,
                "key": "_wc_shipment_tracking_items",
                "value": [
                    {
                        "tracking_provider": "MRW",
                        "tracking_number": "MRW555666777",
                        "custom_tracking_link": "https://www.mrw.es/seguimiento",
                        "date_shipped": "1760610000",
                    },
                ],
            },
        ],
    }
    order.update(overrides)
    return order


class Shop:
    def __init__(self, orders: list[dict[str, Any]]) -> None:
        self.orders = orders
        self.requests: list[httpx.Request] = []

    def __call__(self, request: httpx.Request) -> httpx.Response:
        self.requests.append(request)
        expected = "Basic " + base64.b64encode(f"{CK}:{CS}".encode()).decode()
        if request.headers.get("Authorization") != expected:
            return httpx.Response(401, json={"code": "woocommerce_rest_cannot_view"})
        if request.url.path == "/wp-json/wc/v3/orders":
            return httpx.Response(200, json=self.orders)
        return httpx.Response(404)


def source(shop: Shop, *, key: str = CK) -> WooCommerceOrders:
    return WooCommerceOrders(
        woocommerce_client(
            SHOP, key, CS, guard=HostGuard(allow_private=True), transport=httpx.MockTransport(shop)
        )
    )


async def test_translates_a_woocommerce_order_to_the_common_format() -> None:
    shop = Shop([woo_order("ES-1001")])

    order = await source(shop).find_order("ES-1001", CLIENTE)

    assert order is not None
    assert order.number == "ES-1001"
    assert order.status == "shipped"  # completed = preparado y enviado
    assert order.customer_name == "Elena Ruiz"
    assert order.placed_at == "2026-10-15T09:20:00+00:00"
    assert order.total == 245.0
    assert [(i.name, i.quantity, i.unit_price, i.sku) for i in order.items] == [
        ("Sofá Nórdico", 1, 199.0, "SOF-1"),
        ("Cojín lino", 2, 23.0, None),
    ]
    assert order.shipment is not None
    assert order.shipment.carrier == "MRW"
    assert order.shipment.tracking_number == "MRW555666777"
    assert order.shipment.shipped_at == "2025-10-16T10:20:00+00:00"
    assert order.shipping_city == "Getxo"
    request = shop.requests[0]
    assert request.url.params["search"] == CLIENTE
    assert request.url.params["per_page"] == "100"


async def test_finds_the_visible_number_not_the_internal_id() -> None:
    shop = Shop([woo_order(1002, id=812), woo_order("1001", id=811)])

    assert (await source(shop).find_order("1001", CLIENTE)) is not None
    assert await source(shop).find_order("812", CLIENTE) is None  # el id interno no vale


@pytest.mark.parametrize(
    ("woo", "common"),
    [
        ("pending", "pending_payment"),
        ("on-hold", "pending_payment"),
        ("failed", "pending_payment"),
        ("processing", "processing"),
        ("completed", "shipped"),
        ("cancelled", "cancelled"),
        ("refunded", "returned"),
        ("wc-preparing-custom", "processing"),  # estado de un plugin
    ],
)
async def test_maps_the_woocommerce_statuses(woo: str, common: str) -> None:
    order = await source(Shop([woo_order("1", status=woo)])).find_order("1", CLIENTE)

    assert order is not None
    assert order.status == common


async def test_search_matches_are_filtered_by_the_exact_email() -> None:
    # `search` también encuentra pedidos donde el texto aparece en otro campo.
    shop = Shop([woo_order("1"), woo_order("2", email="otra.elena@cliente.test")])

    orders = await source(shop).customer_orders(CLIENTE, 10)

    assert [o.number for o in orders] == ["1"]


async def test_an_order_without_tracking_plugin_has_no_shipment() -> None:
    order = await source(Shop([woo_order("1", meta_data=[])])).find_order("1", CLIENTE)

    assert order is not None
    assert order.shipment is None


async def test_bad_keys_and_unexpected_answers_are_errors_never_no_orders() -> None:
    with pytest.raises(IntegrationError, match="rechazó las credenciales"):
        await source(Shop([]), key="ck_mala").customer_orders(CLIENTE, 5)

    def html(request: httpx.Request) -> httpx.Response:
        return httpx.Response(200, json={"code": "rest_no_route"})

    broken = WooCommerceOrders(
        woocommerce_client(
            SHOP, CK, CS, guard=HostGuard(allow_private=True), transport=httpx.MockTransport(html)
        )
    )
    with pytest.raises(IntegrationError, match="respuesta no válida"):
        await broken.customer_orders(CLIENTE, 5)


async def test_the_keys_never_reach_the_logs(caplog: pytest.LogCaptureFixture) -> None:
    caplog.set_level(logging.DEBUG)

    with pytest.raises(IntegrationError):
        await source(Shop([]), key="ck_mala").customer_orders(CLIENTE, 5)

    assert "tienda.empresa.test" in caplog.text
    assert CS not in caplog.text
    assert "ck_mala" not in caplog.text


async def test_laravel_configuration_connects_the_shop_through_business_tools() -> None:
    shop = Shop([woo_order("ES-1001"), woo_order("ES-1002", email="marcos@cliente.test")])
    config = {
        "orders": {
            "type": "woocommerce",
            "base_url": SHOP,
            "consumer_key": CK,
            "consumer_secret": CS,
        }
    }

    def handler(request: httpx.Request) -> httpx.Response:
        if request.url.host == "laravel.test":
            return httpx.Response(200, json={"tenant_id": 9, **config})
        return shop(request)

    tools = BusinessTools(
        LaravelIntegrations(
            "http://laravel.test",
            "token-de-datos",
            transport=httpx.MockTransport(handler),
            guard=HostGuard(allow_private=True),
        )
    )

    order = await tools.get_order(9, CLIENTE, "#ES-1001")
    assert order["shipment"]["tracking_number"] == "MRW555666777"
    with pytest.raises(ToolInputError, match=NOT_FOUND):
        await tools.get_order(9, CLIENTE, "ES-1002")  # de otro cliente
    availability = await tools.availability(9)
    assert availability["get_order"] is True
    assert availability["get_invoice"] is False  # WooCommerce no trae facturas
