"""Configuración del servicio, leída de variables de entorno (prefijo AI_SERVICE_)."""

from enum import StrEnum
from functools import lru_cache
from typing import Literal

from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class Environment(StrEnum):
    LOCAL = "local"
    TESTING = "testing"
    PRODUCTION = "production"


LogLevel = Literal["DEBUG", "INFO", "WARNING", "ERROR", "CRITICAL"]


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

    @property
    def is_production(self) -> bool:
        return self.environment is Environment.PRODUCTION


@lru_cache
def get_settings() -> Settings:
    return Settings()
