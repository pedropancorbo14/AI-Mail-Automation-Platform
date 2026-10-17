"""`ai-service-worker`: el proceso que hace el trabajo.

Un solo proceso asyncio con dos tareas: el consumidor de eventos y la app HTTP
de operación (/health, /ready, /metrics). Van juntos porque las métricas que
importan (emails, llamadas al modelo) las genera el consumidor: cada réplica
expone las suyas y Prometheus las suma.

Parada limpia (SIGTERM de Docker o Kubernetes): el consumidor deja de leer,
termina los emails en curso (lo no confirmado lo reclamará otra réplica) y
después se cierra la app HTTP.
"""

import asyncio
import contextlib
import signal
import sys
import time
from collections.abc import Generator

import uvicorn
from redis.asyncio import Redis

from ai_service.application.errors import LLMError
from ai_service.application.use_cases.process_email import ProcessEmail
from ai_service.config import Settings, get_settings
from ai_service.entrypoints.consumer import ConsumerOptions, EventConsumer
from ai_service.entrypoints.container import build_agent
from ai_service.entrypoints.http.app import create_app
from ai_service.infrastructure.checkpoints.postgres import PostgresCheckpoints
from ai_service.infrastructure.events.redis_streams import (
    RECEIVED,
    RedisStreamConsumer,
    RedisStreamsEventBus,
)
from ai_service.infrastructure.observability.logging import configure_logging, get_logger
from ai_service.infrastructure.observability.metrics import ServiceMetrics

log = get_logger(__name__)


class _Server(uvicorn.Server):
    """Las señales las gestiona el worker (uvicorn las relanzaría al salir y
    cortaría a medias los emails en curso)."""

    @contextlib.contextmanager
    def capture_signals(self) -> Generator[None]:
        yield


def redis_client(settings: Settings) -> Redis:
    """El timeout de lectura del socket TIENE que superar el BLOCK de XREADGROUP:
    redis-py trae uno por defecto y cortaba cada lectura bloqueante (encontrado
    al probar en el stack con BLOCK 5000)."""
    return Redis.from_url(
        settings.redis_url.get_secret_value(),
        socket_timeout=settings.read_block_ms / 1000 + 10,
        socket_connect_timeout=5,
        health_check_interval=30,
    )


def build_consumer(
    settings: Settings,
    redis: Redis,
    metrics: ServiceMetrics,
    checkpoints: PostgresCheckpoints | None = None,
) -> EventConsumer:
    bus = RedisStreamsEventBus(
        redis, prefix=settings.stream_prefix, maxlen=settings.outgoing_maxlen
    )
    stream = RedisStreamConsumer(
        redis,
        stream=f"{settings.stream_prefix}{RECEIVED}",
        group=settings.consumer_group,
        consumer=settings.consumer_name,
    )
    return EventConsumer(
        stream,
        ProcessEmail(
            build_agent(settings, checkpointer=checkpoints.saver if checkpoints else None), bus
        ),
        bus,
        metrics,
        ConsumerOptions(
            concurrency=settings.concurrency,
            read_block_ms=settings.read_block_ms,
            claim_idle_ms=settings.claim_idle_ms,
            max_deliveries=settings.max_deliveries,
            retention_hours=settings.stream_retention_hours,
            processed_ttl_seconds=settings.processed_ttl_seconds,
            checkpoint_ttl_hours=settings.checkpoint_ttl_hours,
        ),
        checkpoints,
    )


async def serve(settings: Settings) -> None:
    metrics = ServiceMetrics(settings.environment.value)
    redis = redis_client(settings)
    checkpoints = await open_checkpoints(settings)
    consumer = build_consumer(settings, redis, metrics, checkpoints)
    stale_after = max(60.0, settings.read_block_ms / 1000 * 3)

    async def readiness() -> dict[str, bool]:
        try:
            redis_ok = bool(await redis.ping())
        except Exception:
            redis_ok = False
        alive = time.time() - consumer.last_heartbeat < stale_after
        checks = {"redis": redis_ok, "consumer": alive}
        if checkpoints is not None:
            checks["checkpoints"] = await checkpoints.ping()
        return checks

    server = _Server(
        uvicorn.Config(
            create_app(settings, metrics, readiness=readiness),
            host=settings.http_host,
            port=settings.http_port,
            log_config=None,
            access_log=False,
        )
    )
    stop = asyncio.Event()
    loop = asyncio.get_running_loop()
    for sig in (signal.SIGTERM, signal.SIGINT):
        loop.add_signal_handler(sig, stop.set)

    http = asyncio.create_task(server.serve())
    try:
        await consumer.run(stop)
    finally:
        server.should_exit = True
        await http
        await redis.aclose()
        if checkpoints is not None:
            await checkpoints.aclose()


async def open_checkpoints(settings: Settings) -> PostgresCheckpoints | None:
    """Abre (y migra) el almacén de checkpoints si está configurado."""
    if settings.checkpoint_database_url is None:
        return None
    return await PostgresCheckpoints.open(settings.checkpoint_database_url.get_secret_value())


def main() -> None:
    settings = get_settings()
    configure_logging(settings.log_level)
    try:
        asyncio.run(serve(settings))
    except LLMError as error:  # p. ej. sin clave: se dice claro y se sale
        log.error("worker_misconfigured", error=error.message)
        sys.exit(1)
