"""Configuración del servicio, leída de variables de entorno (prefijo AI_SERVICE_)."""

import socket
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

    # --- Modelo ---
    # anthropic (Claude real) o simulated (reglas por palabras clave, sin coste;
    # solo en local y testing: nunca responde emails reales sin modelo).
    llm_provider: Literal["anthropic", "simulated"] = "anthropic"
    simulated_delay_ms: int = Field(default=300, ge=0, le=10_000)

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

    # --- RAG (documentación de la empresa) ---
    # Rol de PostgreSQL de SOLO LECTURA (ai_service_reader, con RLS por empresa).
    # Sin URL, el grafo redacta sin documentación.
    database_url: SecretStr | None = None
    ollama_url: str = "http://ollama:11434"
    # El MISMO modelo con el que Laravel indexó los documentos.
    embedding_model: str = "bge-m3"
    embedding_dimensions: int = Field(default=1024, ge=1)
    embedding_timeout_seconds: float = Field(default=30.0, gt=0)
    rag_limit: int = Field(default=5, ge=1, le=20)
    rag_min_similarity: float = Field(default=0.45, ge=0, le=1)

    # --- Herramientas (servidor MCP: CRM, ERP, tickets) ---
    # Sin URL, el grafo no consulta los sistemas de la empresa.
    mcp_url: str | None = None
    mcp_token: SecretStr | None = None
    mcp_timeout_seconds: float = Field(default=10.0, gt=0)
    # Consultas del plan determinista, por especialista.
    max_tool_calls: int = Field(default=5, ge=0, le=10)
    # Consultas que el modelo puede pedir al redactar (Fase 5): rondas de
    # redactar ⇄ consultar y consultas en total por email. 0 rondas = no se le
    # ofrecen herramientas.
    max_model_tool_rounds: int = Field(default=2, ge=0, le=5)
    max_model_tool_calls: int = Field(default=3, ge=0, le=10)

    # --- Acciones con aprobación humana (checkpoints de LangGraph) ---
    # Rol ai_service_checkpoints (solo su esquema ai_service). Sin URL, el
    # agente no propone acciones que modifican datos (no podría esperar la
    # aprobación).
    checkpoint_database_url: SecretStr | None = None
    # Una acción que nadie aprueba ni rechaza en este tiempo caduca.
    checkpoint_ttl_hours: int = Field(default=336, ge=1)

    # --- Agente y reglas de decisión ---
    min_confidence: float = Field(default=0.85, ge=0, le=1)
    # Presupuesto de tokens por email (entrada + salida de todas sus llamadas al
    # modelo, también las fallidas). Los precios están en Laravel: aquí se
    # limitan tokens, no dólares. Superado, no se redacta: a una persona.
    max_tokens_per_email: int = Field(default=60_000, ge=1000)
    # Pasos máximos del grafo (protección ante un ciclo por error).
    max_graph_steps: int = Field(default=15, ge=5, le=100)
    # Tope del grafo entero (incluye los reintentos del SDK de cada llamada).
    agent_timeout_seconds: float = Field(default=240.0, gt=0)

    # --- Eventos (Redis Streams) ---
    # Base de datos propia (3) y sin prefijo de claves: Laravel usa la misma con
    # su conexión "streams". En el compose lleva la contraseña de Redis.
    redis_url: SecretStr = SecretStr("redis://localhost:6379/3")
    stream_prefix: str = ""
    consumer_group: str = "ai-service"
    # Nombre del consumidor dentro del grupo: único por réplica (en Kubernetes, el pod).
    consumer_name: str = Field(default_factory=socket.gethostname)
    # Emails que se procesan a la vez por réplica (llamadas al modelo en paralelo).
    concurrency: int = Field(default=4, ge=1, le=64)
    read_block_ms: int = Field(default=5000, ge=100)
    # Un mensaje sin confirmar más de esto se considera abandonado (réplica caída o
    # error temporal) y otra lo reclama. Mayor que el tope del agente.
    claim_idle_ms: int = Field(default=300_000, ge=1000)
    # Entregas antes de darlo por fallido (publica email.processing_failed.v1).
    max_deliveries: int = Field(default=3, ge=1, le=20)
    # El cuerpo del email es dato personal: lo que quede en los streams se recorta.
    stream_retention_hours: int = Field(default=168, ge=1)
    outgoing_maxlen: int = Field(default=100_000, ge=1000)
    processed_ttl_seconds: int = Field(default=86_400, ge=60)

    # POST /debug/process: procesa un email de prueba con el modelo real (cuesta
    # dinero). Desactivado por defecto y nunca disponible en producción.
    debug_endpoints: bool = False

    @property
    def is_production(self) -> bool:
        return self.environment is Environment.PRODUCTION


@lru_cache
def get_settings() -> Settings:
    return Settings()
