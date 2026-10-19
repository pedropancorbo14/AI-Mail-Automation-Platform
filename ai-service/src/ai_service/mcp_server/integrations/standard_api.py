"""Proveedor de la API estándar de la plataforma (`contracts/integrations/`).

Lo implementa el ERP o programa propio de una empresa, y la propia
plataforma para los pedidos importados por CSV. Reglas de este lado:

- Token `Authorization: Bearer`, sin redirecciones (podrían llevar a un host
  interno), tiempo máximo y tamaño máximo de respuesta (se corta mientras se
  descarga).
- Cada respuesta se valida contra el contrato (modelos equivalentes a los
  JSON Schema; un test comprueba que aceptan y rechazan los mismos ejemplos).
- Cualquier fallo es `IntegrationError` con un mensaje para la IA y el panel,
  **nunca** "no encontrado" (solo un 404 de un pedido o factura concretos lo
  es). El token nunca aparece en mensajes ni logs.

La URL base sale de la configuración (Laravel), nunca de un email ni del
modelo. La que escribe una empresa se comprueba antes de cada petición
(`HostGuard`, anti-SSRF): Laravel ya la validó al guardarla, pero el DNS
puede cambiar después.
"""

import asyncio
import ipaddress
import logging
import socket
from datetime import datetime
from typing import Annotated, Literal
from urllib.parse import quote, urlsplit

import httpx
from pydantic import BaseModel, ConfigDict, Field, ValidationError

from ai_service.mcp_server.integrations.contracts import IntegrationError, TenantIntegrations
from ai_service.mcp_server.integrations.models import Invoice, Order, OrderItem, Shipment

log = logging.getLogger(__name__)

MAX_LIMIT = 100
EMAIL = r"^[^@\s]+@[^@\s]+$"

Currency = Annotated[str, Field(pattern=r"^[A-Z]{3}$")]
Text = Annotated[str, Field(max_length=300)]


class _Payload(BaseModel):
    # Lector tolerante (los campos que no conocemos se ignoran) pero estricto
    # con los tipos, como el JSON Schema: "12.5" no es un importe ni 1700000000
    # una fecha (en modo estricto, las fechas en JSON siguen siendo texto ISO).
    model_config = ConfigDict(extra="ignore", frozen=True, strict=True)


class ItemPayload(_Payload):
    name: Annotated[str, Field(min_length=1, max_length=300)]
    quantity: Annotated[int, Field(ge=1)]
    unit_price: Annotated[float, Field(ge=0)] | None = None
    sku: Annotated[str, Field(max_length=100)] | None = None


class ShipmentPayload(_Payload):
    carrier: Annotated[str, Field(max_length=100)] | None = None
    tracking_number: Annotated[str, Field(max_length=100)] | None = None
    tracking_url: Annotated[str, Field(pattern=r"^https?://\S+$", max_length=2000)] | None = None
    shipped_at: datetime | None = None
    estimated_delivery: datetime | None = None
    delivered_at: datetime | None = None


class OrderPayload(_Payload):
    number: Annotated[str, Field(min_length=1, max_length=64)]
    customer_email: Annotated[str, Field(pattern=EMAIL, max_length=254)]
    status: Literal[
        "pending_payment", "processing", "shipped", "delivered", "cancelled", "returned"
    ]
    placed_at: datetime | None = None
    customer_name: Annotated[str, Field(max_length=200)] | None = None
    total: Annotated[float, Field(ge=0)] | None = None
    currency: Currency | None = None
    items: Annotated[list[ItemPayload], Field(max_length=100)] = []
    shipment: ShipmentPayload | None = None
    shipping_city: Annotated[str, Field(max_length=100)] | None = None
    shipping_country: Annotated[str, Field(pattern=r"^[A-Z]{2}$")] | None = None


class InvoicePayload(_Payload):
    number: Annotated[str, Field(min_length=1, max_length=64)]
    customer_email: Annotated[str, Field(pattern=EMAIL, max_length=254)]
    status: Literal["paid", "refunded", "pending"]
    order_number: Annotated[str, Field(max_length=64)] | None = None
    issued_at: datetime | None = None
    total: float | None = None
    currency: Currency | None = None


class OrderListPayload(_Payload):
    data: Annotated[list[OrderPayload], Field(max_length=MAX_LIMIT)]


class InvoiceListPayload(_Payload):
    data: Annotated[list[InvoicePayload], Field(max_length=MAX_LIMIT)]


def _iso(value: datetime | None) -> str | None:
    return value.isoformat() if value else None


def to_order(p: OrderPayload) -> Order:
    s = p.shipment
    return Order(
        number=p.number,
        customer_email=p.customer_email,
        status=p.status,
        placed_at=_iso(p.placed_at),
        customer_name=p.customer_name,
        total=p.total,
        currency=p.currency,
        items=tuple(OrderItem(i.name, i.quantity, i.unit_price, i.sku) for i in p.items),
        shipment=Shipment(
            carrier=s.carrier,
            tracking_number=s.tracking_number,
            tracking_url=s.tracking_url,
            shipped_at=_iso(s.shipped_at),
            estimated_delivery=_iso(s.estimated_delivery),
            delivered_at=_iso(s.delivered_at),
        )
        if s
        else None,
        shipping_city=p.shipping_city,
        shipping_country=p.shipping_country,
    )


def to_invoice(p: InvoicePayload) -> Invoice:
    return Invoice(
        number=p.number,
        customer_email=p.customer_email,
        status=p.status,
        order_number=p.order_number,
        issued_at=_iso(p.issued_at),
        total=p.total,
        currency=p.currency,
    )


_LINK_LOCAL = (ipaddress.ip_network("169.254.0.0/16"), ipaddress.ip_network("fe80::/10"))
_ZERO = ipaddress.ip_network("0.0.0.0/8")


class HostGuard:
    """Anti-SSRF para la URL que escribe una empresa (mismas reglas que
    `MailHostGuard` de Laravel): HTTPS, y el host no puede resolver a la red
    interna. Enlace local (metadatos de la nube) y 0.0.0.0/8, nunca; el resto
    de direcciones privadas y HTTP sin TLS, solo con `allow_private` (local)."""

    def __init__(self, *, allow_private: bool = False) -> None:
        self._allow_private = allow_private

    async def reject(self, url: str) -> str | None:
        parts = urlsplit(url)
        if parts.scheme != "https" and not (parts.scheme == "http" and self._allow_private):
            return "la dirección tiene que empezar por https://"
        if not parts.hostname:
            return "la dirección no es válida"
        try:
            infos = await asyncio.get_running_loop().getaddrinfo(
                parts.hostname, parts.port or 443, type=socket.SOCK_STREAM
            )
        except OSError:
            return None if self._allow_private else f"no se puede resolver {parts.hostname}"
        for info in infos:
            ip = ipaddress.ip_address(info[4][0])
            if isinstance(ip, ipaddress.IPv6Address) and ip.ipv4_mapped:
                ip = ip.ipv4_mapped
            if any(ip in net for net in _LINK_LOCAL) or ip in _ZERO:
                return f"{parts.hostname} apunta a una dirección interna no permitida"
            if not self._allow_private and not ip.is_global:
                return f"{parts.hostname} apunta a una dirección interna no permitida"
        return None


class StandardApiClient:
    """GET a la API de una empresa. `system` = "pedidos" / "facturación" (mensajes)."""

    def __init__(
        self,
        base_url: str,
        token: str,
        *,
        timeout: float = 5.0,
        max_bytes: int = 1024**2,
        transport: httpx.AsyncBaseTransport | None = None,
        guard: HostGuard | None = None,
    ) -> None:
        self._base = base_url.rstrip("/")
        self._host = urlsplit(self._base).hostname or "?"
        self._token = token
        self._timeout = timeout
        self._max = max_bytes
        self._transport = transport
        self._guard = guard

    async def get_one(self, path: str, params: dict[str, str | int], system: str) -> bytes | None:
        """Un pedido o factura concretos: None si es un 404 (no existe o es de otro)."""
        return await self._get(path, params, system, single=True)

    async def get_list(self, path: str, params: dict[str, str | int], system: str) -> bytes:
        """Una lista: un 404 aquí es un error (la API no está bien implementada)."""
        body = await self._get(path, params, system, single=False)
        if body is None:  # pragma: no cover - con single=False nunca es None
            raise IntegrationError(f"El sistema de {system} de la empresa no responde.")
        return body

    async def _get(
        self, path: str, params: dict[str, str | int], system: str, *, single: bool
    ) -> bytes | None:
        if self._guard is not None and (reason := await self._guard.reject(self._base)):
            self._warn(path, None, reason)
            raise IntegrationError(
                f"El sistema de {system} de la empresa no se puede consultar: {reason}."
            )
        headers = {
            "Authorization": f"Bearer {self._token}",
            "Accept": "application/json",
            "User-Agent": "ai-mail-automation/1",
        }
        try:
            async with (
                httpx.AsyncClient(
                    timeout=self._timeout, follow_redirects=False, transport=self._transport
                ) as client,
                client.stream("GET", self._base + path, params=params, headers=headers) as response,
            ):
                status = response.status_code
                if status == 404 and single:
                    return None
                if status in (401, 403):
                    self._warn(path, status)
                    raise IntegrationError(
                        f"El sistema de {system} de la empresa rechazó las credenciales "
                        "de la plataforma."
                    )
                if status == 429 or status >= 500:
                    self._warn(path, status)
                    raise IntegrationError(f"El sistema de {system} de la empresa no responde.")
                if status != 200:  # redirección, 404 de una lista, 400…
                    self._warn(path, status)
                    raise IntegrationError(
                        f"El sistema de {system} de la empresa devolvió una respuesta no válida."
                    )
                body = bytearray()
                async for chunk in response.aiter_bytes():
                    body.extend(chunk)
                    if len(body) > self._max:
                        self._warn(path, status, "respuesta demasiado grande")
                        raise IntegrationError(
                            f"El sistema de {system} de la empresa devolvió una respuesta "
                            "no válida."
                        )
                return bytes(body)
        except httpx.HTTPError as error:
            self._warn(path, None, type(error).__name__)
            raise IntegrationError(f"El sistema de {system} de la empresa no responde.") from error

    def _warn(self, path: str, status: int | None, detail: str = "") -> None:
        log.warning(
            "standard_api_error host=%s path=%s status=%s %s", self._host, path, status, detail
        )


def _parse[T: BaseModel](model: type[T], body: bytes, system: str) -> T:
    try:
        return model.model_validate_json(body)
    except ValidationError as error:
        log.warning("standard_api_invalid_response errors=%d", error.error_count())
        raise IntegrationError(
            f"El sistema de {system} de la empresa devolvió una respuesta no válida."
        ) from error


def _limit(limit: int) -> int:
    return max(1, min(limit, MAX_LIMIT))


class StandardApiOrders:
    """`OrderSource` sobre la API estándar."""

    SYSTEM = "pedidos"

    def __init__(self, client: StandardApiClient) -> None:
        self._client = client

    async def find_order(self, number: str, customer_email: str) -> Order | None:
        body = await self._client.get_one(
            f"/v1/orders/{quote(number, safe='')}",
            {"customer_email": customer_email},
            self.SYSTEM,
        )
        return None if body is None else to_order(_parse(OrderPayload, body, self.SYSTEM))

    async def customer_orders(self, customer_email: str, limit: int) -> list[Order]:
        body = await self._client.get_list(
            "/v1/orders",
            {"customer_email": customer_email, "limit": _limit(limit)},
            self.SYSTEM,
        )
        return [to_order(o) for o in _parse(OrderListPayload, body, self.SYSTEM).data]


class StandardApiInvoices:
    """`InvoiceSource` sobre la API estándar."""

    SYSTEM = "facturación"

    def __init__(self, client: StandardApiClient) -> None:
        self._client = client

    async def find_invoice(self, number: str, customer_email: str) -> Invoice | None:
        body = await self._client.get_one(
            f"/v1/invoices/{quote(number, safe='')}",
            {"customer_email": customer_email},
            self.SYSTEM,
        )
        return None if body is None else to_invoice(_parse(InvoicePayload, body, self.SYSTEM))

    async def customer_invoices(self, customer_email: str, limit: int) -> list[Invoice]:
        body = await self._client.get_list(
            "/v1/invoices",
            {"customer_email": customer_email, "limit": _limit(limit)},
            self.SYSTEM,
        )
        return [to_invoice(i) for i in _parse(InvoiceListPayload, body, self.SYSTEM).data]


def standard_api_integrations(
    client: StandardApiClient, *, orders: bool = True, invoices: bool = True
) -> TenantIntegrations:
    """Lo que ofrece una empresa con la API estándar (puede implementar solo
    los pedidos). Sin helpdesk: los tickets van por otro proveedor."""
    return TenantIntegrations(
        orders=StandardApiOrders(client) if orders else None,
        invoices=StandardApiInvoices(client) if invoices else None,
    )
