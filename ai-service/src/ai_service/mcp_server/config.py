"""Configuración del servidor MCP (prefijo AI_SERVICE_MCP_)."""

from pathlib import Path

from pydantic import Field, SecretStr
from pydantic_settings import BaseSettings, SettingsConfigDict

_DATA = Path(__file__).parent / "data"


class McpSettings(BaseSettings):
    model_config = SettingsConfigDict(
        env_prefix="AI_SERVICE_MCP_", env_file=".env", extra="ignore", frozen=True
    )

    # Token que debe enviar ai-service (Authorization: Bearer). Sin token no arranca.
    token: SecretStr | None = None
    host: str = "0.0.0.0"  # noqa: S104 - dentro del contenedor; el compose no lo publica
    port: int = Field(default=8100, ge=1, le=65535)
    # Datos de demostración (pedidos y facturas de "Empresa Demo", coherentes
    # con los emails de prueba). En la Fase 14, integraciones reales.
    orders_file: Path = _DATA / "orders.json"
    tickets_file: Path = _DATA / "tickets.json"
    # Empresa (tenant_id de los eventos) → clave de los ficheros de demostración.
    demo_tenants: dict[int, str] = Field(default_factory=lambda: {1: "empresa-demo"})
    # Laravel: qué tiene conectado cada empresa y los pedidos importados por CSV
    # (Fase 14). Sin URL, solo los datos de demostración. Con URL, el token es
    # obligatorio (AI_SERVICE_DATA_TOKEN en Laravel).
    laravel_url: str | None = None
    laravel_token: SecretStr | None = None
    integrations_cache_seconds: float = Field(default=60.0, ge=0)
    # La API estándar del sistema de una empresa puede apuntar a hosts privados
    # (y por HTTP) solo en local: un ERP de pruebas en la red de Docker. En
    # cualquier otro entorno se ignora aunque se active (como en Laravel).
    allow_private_hosts: bool = False
    environment: str = Field(default="production", validation_alias="AI_SERVICE_ENVIRONMENT")

    @property
    def private_hosts_allowed(self) -> bool:
        return self.allow_private_hosts and self.environment in ("local", "testing")
