"""`IntegrationResolver` que pregunta a Laravel qué tiene conectado cada empresa.

`GET {laravel}/internal/integrations/{empresa}` (token de datos) dice, por
sistema, de dónde salen sus datos: `standard_api` (el sistema propio de la
empresa: URL y clave), `woocommerce` (su tienda: URL y claves de API) o
`csv` (pedidos importados, que Laravel sirve con la API estándar en
`/internal/standard-api/{empresa}`). Si la empresa no tiene nada configurado
se usa el resolvedor de respaldo (los datos de demostración).

Caché corta por empresa (la consulta se hace en cada herramienta). Si Laravel
no responde se sigue con la última configuración conocida; sin ninguna, error
legible: nunca se responde como si la empresa no tuviera datos. La clave de
la empresa nunca aparece en logs (SecretStr; de los errores, solo el tipo).
"""

import logging
import time
from collections.abc import Callable

import httpx
from pydantic import BaseModel, ConfigDict, SecretStr, ValidationError

from ai_service.mcp_server.integrations.contracts import (
    IntegrationError,
    TenantIntegrations,
)
from ai_service.mcp_server.integrations.demo import DemoIntegrations
from ai_service.mcp_server.integrations.standard_api import (
    HostGuard,
    StandardApiAppointments,
    StandardApiClient,
    StandardApiInvoices,
    StandardApiOrders,
)
from ai_service.mcp_server.integrations.woocommerce import WooCommerceOrders, woocommerce_client

log = logging.getLogger(__name__)

UNAVAILABLE = "No se pudo consultar qué sistemas tiene conectados la empresa. Inténtalo más tarde."
STANDARD_API = "standard_api"
WOOCOMMERCE = "woocommerce"
CSV = "csv"


class _Source(BaseModel):
    model_config = ConfigDict(extra="ignore", frozen=True)
    type: str
    # standard_api y woocommerce: el sistema de la empresa.
    base_url: str | None = None
    token: SecretStr | None = None
    # woocommerce: sus claves de API.
    consumer_key: SecretStr | None = None
    consumer_secret: SecretStr | None = None


class _Config(BaseModel):
    model_config = ConfigDict(extra="ignore", frozen=True)
    orders: _Source | None = None
    invoices: _Source | None = None
    tickets: _Source | None = None
    appointments: _Source | None = None
    # Para los datos de demostración (p. ej. "clinica-demo"), sin configurar ids.
    tenant_slug: str | None = None


class LaravelIntegrations:
    def __init__(
        self,
        base_url: str,
        token: str,
        *,
        fallback: DemoIntegrations | None = None,
        ttl_seconds: float = 60.0,
        timeout: float = 5.0,
        transport: httpx.AsyncBaseTransport | None = None,
        clock: Callable[[], float] = time.monotonic,
        guard: HostGuard | None = None,
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
        # Para las URL que escribe una empresa (no para Laravel, que es interno).
        self._guard = guard or HostGuard()
        self._cache: dict[int, tuple[float, _Config]] = {}

    async def for_tenant(self, tenant_id: int) -> TenantIntegrations:
        config = await self._config(tenant_id)
        integrations = TenantIntegrations(
            orders=self._orders(tenant_id, config.orders),
            invoices=self._invoices(tenant_id, config.invoices),
            appointments=self._appointments(tenant_id, config.appointments),
        )
        if integrations == TenantIntegrations() and self._fallback is not None:
            return await self._fallback.for_tenant(tenant_id, slug=config.tenant_slug)
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
            # Solo el tipo: un error de validación incluiría los valores (la clave).
            log.warning(
                "integrations_config_unavailable tenant_id=%s error=%s",
                tenant_id,
                type(error).__name__,
            )
            if cached:
                return cached[1]  # mejor la última conocida que dejar a la IA sin datos
            raise IntegrationError(UNAVAILABLE) from error
        self._cache[tenant_id] = (self._clock(), config)
        return config

    def _client(self, tenant_id: int, source: _Source) -> StandardApiClient | None:
        if source.type == CSV:
            return StandardApiClient(
                f"{self._base}/internal/standard-api/{tenant_id}",
                self._token,
                timeout=self._timeout,
                transport=self._transport,
            )
        if source.type == STANDARD_API and source.base_url and source.token:
            return StandardApiClient(
                source.base_url,
                source.token.get_secret_value(),
                timeout=self._timeout,
                transport=self._transport,
                guard=self._guard,
            )
        # Tipo de una versión más nueva de Laravel, o configuración incompleta.
        log.warning("integrations_unknown_source tenant_id=%s type=%s", tenant_id, source.type)
        return None

    def _orders(
        self, tenant_id: int, source: _Source | None
    ) -> StandardApiOrders | WooCommerceOrders | None:
        if source is not None and source.type == WOOCOMMERCE:
            if not (source.base_url and source.consumer_key and source.consumer_secret):
                log.warning("integrations_incomplete tenant_id=%s type=%s", tenant_id, source.type)
                return None
            return WooCommerceOrders(
                woocommerce_client(
                    source.base_url,
                    source.consumer_key.get_secret_value(),
                    source.consumer_secret.get_secret_value(),
                    guard=self._guard,
                    timeout=self._timeout,
                    transport=self._transport,
                )
            )
        client = self._client(tenant_id, source) if source else None
        return StandardApiOrders(client) if client else None

    def _invoices(self, tenant_id: int, source: _Source | None) -> StandardApiInvoices | None:
        # Las facturas solo vienen del sistema propio (el CSV es de pedidos).
        if source is None or source.type != STANDARD_API:
            return None
        client = self._client(tenant_id, source)
        return StandardApiInvoices(client) if client else None

    def _appointments(
        self, tenant_id: int, source: _Source | None
    ) -> StandardApiAppointments | None:
        # Las citas solo vienen del sistema propio de la empresa (su agenda).
        if source is None or source.type != STANDARD_API:
            return None
        client = self._client(tenant_id, source)
        return StandardApiAppointments(client) if client else None
