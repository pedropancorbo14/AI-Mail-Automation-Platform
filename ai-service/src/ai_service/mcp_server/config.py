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
    # Datos de demostración: los MISMOS pedidos que el DemoOrderProvider de Laravel.
    orders_file: Path = Path("database/seeders/demo/orders.json")
    tickets_file: Path = _DATA / "tickets.json"
    # Empresa (tenant_id de los eventos) → clave de los ficheros de demostración.
    demo_tenants: dict[int, str] = Field(default_factory=lambda: {1: "empresa-demo"})
