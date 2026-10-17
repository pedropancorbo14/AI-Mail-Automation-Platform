"""Configuración del servicio, leída de variables de entorno (prefijo AI_SERVICE_)."""

from enum import StrEnum
from functools import lru_cache
from typing import Literal

from pydantic import Field, SecretStr
from pydantic_settings import BaseSettings, SettingsConfigDict


class Environment(StrEnum):
    LOCAL = "local"
    TESTING = "testing"
    PRODUCTION = "production"


LogLevel = Literal["DEBUG", "INFO", "WARNING", "ERROR", "CRITICAL"]
Effort = Literal["low", "medium", "high", "xhigh", "max"]


class Settings(BaseSettings):
    """Valores con defecto seguro para desarrollo; producción los fija por entorno."""

    model_config = SettingsConfigDict(
        env_prefix="AI_SERVICE_",
        env_file=".env",
        extra="ignore",
        frozen=True,
    )

    environment: Environment = Environment.LOCAL
    log_level: LogLevel = "INFO"
    http_host: str = "0.0.0.0"  # noqa: S104 - dentro del contenedor; el compose no lo publica
    http_port: int = Field(default=8000, ge=1, le=65535)

    # --- Modelo (Claude) ---
    # Sin clave el consumidor no arranca con el proveedor anthropic (se dice claro).
    anthropic_api_key: SecretStr | None = None
    anthropic_model: str = "claude-opus-5-5"
    # Si los filtros de seguridad rechazan, Anthropic reintenta con otro modelo en
    # la misma llamada (fallbacks "default"); se sigue comprobando stop_reason.
    anthropic_fallbacks: bool = True
    llm_timeout_seconds: float = Field(default=60.0, gt=0)
    llm_max_retries: int = Field(default=2, ge=0, le=5)  # reintentos del SDK (429, 5xx, red)
    classify_effort: Effort = "low"
    classify_max_tokens: int = Field(default=4096, ge=256)  # incluye el razonamiento
    draft_effort: Effort = "medium"
    draft_max_tokens: int = Field(default=8000, ge=256)
    # Laravel ya recorta el cuerpo; esto es una segunda barrera (avisando al modelo).
    max_body_chars: int = Field(default=20_000, ge=1000)

    # --- Agente y reglas de decisión ---
    min_confidence: float = Field(default=0.85, ge=0, le=1)
    # Tope del grafo entero (incluye los reintentos del SDK de cada llamada).
    agent_timeout_seconds: float = Field(default=240.0, gt=0)

    # POST /debug/process: procesa un email de prueba con el modelo real (cuesta
    # dinero). Desactivado por defecto y nunca disponible en producción.
    debug_endpoints: bool = False

    @property
    def is_production(self) -> bool:
        return self.environment is Environment.PRODUCTION


@lru_cache
def get_settings() -> Settings:
    return Settings()
