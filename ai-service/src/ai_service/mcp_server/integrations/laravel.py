"""`IntegrationResolver` que pregunta a Laravel qué tiene conectado cada empresa.

`GET {laravel}/internal/integrations/{empresa}` (token de datos) dice, por
sistema, de dónde salen sus datos. Hoy: pedidos importados por CSV, que
Laravel sirve con la API estándar en `/internal/standard-api/{empresa}`. Si
la empresa no tiene nada configurado se usa el resolvedor de respaldo (los
datos de demostración).

Caché corta por empresa (la consulta se hace en cada herramienta). Si Laravel
no responde se sigue con la última configuración conocida; sin ninguna, error
legible: nunca se responde como si la empresa no tuviera datos.
"""

import logging
import time
from collections.abc import Callable

import httpx
from pydantic import BaseModel, ConfigDict, ValidationError

from ai_service.mcp_server.integrations.contracts import (
    IntegrationError,
    IntegrationResolver,
    TenantIntegrations,
)
from ai_service.mcp_server.integrations.standard_api import StandardApiClient, StandardApiOrders

log = logging.getLogger(__name__)

UNAVAILABLE = "No se pudo consultar qué sistemas tiene conectados la empresa. Inténtalo más tarde."


class _Source(BaseModel):
    model_config = ConfigDict(extra="ignore", frozen=True)
    type: str


class _Config(BaseModel):
    model_config = ConfigDict(extra="ignore", frozen=True)
    orders: _Source | None = None
    invoices: _Source | None = None
    tickets: _Source | None = None


class LaravelIntegrations:
    def __init__(
        self,
        base_url: str,
        token: str,
        *,
        fallback: IntegrationResolver | None = None,
        ttl_seconds: float = 60.0,
        timeout: float = 5.0,
        transport: httpx.AsyncBaseTransport | None = None,
        clock: Callable[[], float] = time.monotonic,
    ) -> None:
        if not token:
            raise ValueError("Falta el token de datos de Laravel (AI_SERVICE_MCP_LARAVEL_TOKEN).")
        self._base = base_url.rstrip("/")
        self._token = token
        self._fallback = fallback
        self._ttl = ttl_seconds
        self._timeout = timeout
        self._transport = transport
        self._clock = clock
        self._cache: dict[int, tuple[float, _Config]] = {}

    async def for_tenant(self, tenant_id: int) -> TenantIntegrations:
        config = await self._config(tenant_id)
        integrations = self._build(tenant_id, config)
        if integrations == TenantIntegrations() and self._fallback is not None:
            return await self._fallback.for_tenant(tenant_id)
        return integrations

    async def _config(self, tenant_id: int) -> _Config:
        cached = self._cache.get(tenant_id)
        if cached and self._clock() - cached[0] < self._ttl:
            return cached[1]
        try:
            async with httpx.AsyncClient(
                timeout=self._timeout, follow_redirects=False, transport=self._transport
            ) as client:
                response = await client.get(
                    f"{self._base}/internal/integrations/{tenant_id}",
                    headers={
                        "Authorization": f"Bearer {self._token}",
                        "Accept": "application/json",
                    },
                )
            if response.status_code != 200:
                raise IntegrationError(f"Laravel respondió {response.status_code}")
            config = _Config.model_validate_json(response.content)
        except (httpx.HTTPError, ValidationError, IntegrationError) as error:
            log.warning("integrations_config_unavailable tenant_id=%s error=%s", tenant_id, error)
            if cached:
                return cached[1]  # mejor la última conocida que dejar a la IA sin datos
            raise IntegrationError(UNAVAILABLE) from error
        self._cache[tenant_id] = (self._clock(), config)
        return config

    def _build(self, tenant_id: int, config: _Config) -> TenantIntegrations:
        orders = None
        if config.orders is not None:
            if config.orders.type == "csv":
                client = StandardApiClient(
                    f"{self._base}/internal/standard-api/{tenant_id}",
                    self._token,
                    timeout=self._timeout,
                    transport=self._transport,
                )
                orders = StandardApiOrders(client)
            else:  # tipo de una versión más nueva de Laravel
                log.warning(
                    "integrations_unknown_type tenant_id=%s type=%s", tenant_id, config.orders.type
                )
        return TenantIntegrations(orders=orders)
