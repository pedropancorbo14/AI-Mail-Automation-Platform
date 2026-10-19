"""Proveedor de pedidos de WooCommerce (API REST v3, `/wp-json/wc/v3`).

Un traductor: pide los pedidos a la tienda y los convierte al formato común
(`Order`); las reglas (solo el cliente que escribe, mismo mensaje para un
pedido ajeno que para uno inexistente…) siguen en `BusinessTools`.

- **Autenticación:** claves de API de WooCommerce (consumer key y secret) como
  Basic por HTTPS (por HTTP, WooCommerce exige OAuth 1.0a: no se usa).
- **Buscar un pedido:** entre los últimos pedidos del cliente (`search` por
  su email + email exacto comprobado) se busca el **número visible**: los
  plugins de numeración (p. ej. "ES-1001") lo separan del id interno.
- **Seguimiento:** WooCommerce no lo trae de serie; se lee el del plugin
  oficial "Shipment Tracking" (`_wc_shipment_tracking_items`) si existe.
- Formato tolerante (WooCommerce mezcla números y textos), pero una respuesta
  que no es una lista de pedidos es un error, nunca "no tiene pedidos".
"""

import base64
import logging
from datetime import UTC, datetime
from typing import Any

import httpx
from pydantic import BaseModel, ConfigDict, Field, TypeAdapter, ValidationError

from ai_service.mcp_server.integrations.contracts import IntegrationError
from ai_service.mcp_server.integrations.models import Order, OrderItem, Shipment
from ai_service.mcp_server.integrations.standard_api import HostGuard, StandardApiClient

log = logging.getLogger(__name__)

API_PATH = "/wp-json/wc/v3"
SYSTEM = "pedidos (WooCommerce)"
# Pedidos del cliente entre los que se busca un número concreto.
SEARCH_WINDOW = 100

# Estado de WooCommerce → estado común. "completed" = pedido preparado y
# enviado (WooCommerce no sabe si se entregó). Los estados de plugins se
# tratan como "en preparación" (y se registra el aviso).
STATUSES = {
    "pending": "pending_payment",
    "on-hold": "pending_payment",
    "failed": "pending_payment",
    "checkout-draft": "pending_payment",
    "processing": "processing",
    "completed": "shipped",
    "cancelled": "cancelled",
    "refunded": "returned",
}


class _Address(BaseModel):
    model_config = ConfigDict(extra="ignore")
    first_name: str | None = None
    last_name: str | None = None
    email: str | None = None
    city: str | None = None
    country: str | None = None


class _Item(BaseModel):
    model_config = ConfigDict(extra="ignore")
    name: str = ""
    quantity: int = 1
    price: float | None = None
    sku: str | None = None


class _Meta(BaseModel):
    model_config = ConfigDict(extra="ignore")
    key: str = ""
    value: Any = None


class _WooOrder(BaseModel):
    model_config = ConfigDict(extra="ignore")
    id: int
    number: str | int  # texto en la API, pero hay tiendas que lo dan como número
    status: str
    date_created_gmt: str | None = None
    total: float | None = None
    currency: str | None = None
    billing: _Address = Field(default_factory=_Address)
    shipping: _Address = Field(default_factory=_Address)
    line_items: list[_Item] = []
    meta_data: list[_Meta] = []


_ORDERS = TypeAdapter(list[_WooOrder])


def basic_auth(consumer_key: str, consumer_secret: str) -> str:
    return "Basic " + base64.b64encode(f"{consumer_key}:{consumer_secret}".encode()).decode()


def _gmt(value: str | None) -> str | None:
    """WooCommerce da la fecha GMT sin zona: se marca como UTC."""
    if not value:
        return None
    try:
        return datetime.fromisoformat(value).replace(tzinfo=UTC).isoformat()
    except ValueError:
        return None


def _shipment(meta: list[_Meta]) -> Shipment | None:
    items = next((m.value for m in meta if m.key == "_wc_shipment_tracking_items"), None)
    if not isinstance(items, list) or not items or not isinstance(items[-1], dict):
        return None
    last: dict[str, Any] = items[-1]
    shipped = str(last.get("date_shipped") or "")  # marca de tiempo Unix
    url = last.get("custom_tracking_link")
    if not (isinstance(url, str) and url.startswith(("http://", "https://"))):
        url = None
    return Shipment(
        carrier=last.get("tracking_provider") or last.get("custom_tracking_provider") or None,
        tracking_number=last.get("tracking_number") or None,
        tracking_url=url,
        shipped_at=datetime.fromtimestamp(int(shipped), UTC).isoformat()
        if shipped.isdigit()
        else None,
    )


def to_order(woo: _WooOrder) -> Order:
    status = STATUSES.get(woo.status)
    if status is None:
        log.warning("woocommerce_unknown_status status=%s", woo.status)
        status = "processing"
    name = " ".join(p for p in (woo.billing.first_name, woo.billing.last_name) if p) or None
    return Order(
        number=str(woo.number),
        customer_email=(woo.billing.email or "").strip().lower(),
        status=status,
        placed_at=_gmt(woo.date_created_gmt),
        customer_name=name,
        total=woo.total,
        currency=woo.currency,
        items=tuple(OrderItem(i.name, i.quantity, i.price, i.sku or None) for i in woo.line_items),
        shipment=_shipment(woo.meta_data),
        shipping_city=woo.shipping.city or woo.billing.city or None,
        shipping_country=woo.shipping.country or woo.billing.country or None,
    )


class WooCommerceOrders:
    """`OrderSource` sobre la API REST de WooCommerce."""

    def __init__(self, client: StandardApiClient) -> None:
        self._client = client

    async def _customer(self, customer_email: str, limit: int) -> list[Order]:
        body = await self._client.get_list(
            "/orders",
            {"search": customer_email, "per_page": limit, "orderby": "date", "order": "desc"},
            SYSTEM,
        )
        try:
            orders = [to_order(o) for o in _ORDERS.validate_json(body)]
        except ValidationError as error:
            log.warning("woocommerce_invalid_response errors=%d", error.error_count())
            raise IntegrationError(
                f"El sistema de {SYSTEM} de la empresa devolvió una respuesta no válida."
            ) from error
        # `search` busca en más campos: solo los del email exacto.
        email = customer_email.strip().lower()
        return [o for o in orders if o.customer_email == email]

    async def find_order(self, number: str, customer_email: str) -> Order | None:
        wanted = number.strip().lstrip("#").upper()
        orders = await self._customer(customer_email, SEARCH_WINDOW)
        return next((o for o in orders if o.number.upper() == wanted), None)

    async def customer_orders(self, customer_email: str, limit: int) -> list[Order]:
        return await self._customer(customer_email, max(1, min(limit, SEARCH_WINDOW)))


def woocommerce_client(
    site_url: str,
    consumer_key: str,
    consumer_secret: str,
    *,
    guard: HostGuard | None,
    timeout: float = 5.0,
    transport: httpx.AsyncBaseTransport | None = None,
) -> StandardApiClient:
    return StandardApiClient(
        site_url.rstrip("/") + API_PATH,
        "",
        timeout=timeout,
        transport=transport,
        guard=guard,
        authorization=basic_auth(consumer_key, consumer_secret),
    )
