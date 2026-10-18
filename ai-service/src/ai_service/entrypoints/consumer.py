"""Consumidor de un stream de entrada (`email.received.v1` o
`email.review_resumed.v1`): lee del consumer group, ejecuta el caso de uso y
confirma (o no) cada mensaje. El worker arranca uno por stream.

Política por mensaje:

- Procesado, o fallo definitivo publicado → `XACK` + `XDEL` y se marca el
  `event_id` como procesado.
- Ya procesado (entrega repetida) → se confirma sin volver a llamar al modelo.
- No cumple el contrato → a la cola de errores (`.dlq`) y se confirma.
- Error temporal (red, 429, 529, timeout) → no se confirma: otra vuelta lo
  reclama pasado `claim_idle_ms` (eso es la espera entre reintentos).
- Error temporal en la última entrega → publica `email.processing_failed.v1`
  (`timeout`) y confirma.
- Error inesperado → como el temporal; en la última entrega, fallo `internal`
  y a la cola de errores.
- Reanudación que no corresponde a la pausa (otra empresa, email o acción) →
  a la cola de errores sin aplicar nada (log crítico).
"""

import asyncio
import contextlib
import time
from collections.abc import Iterable
from dataclasses import dataclass
from typing import Any, Protocol

from redis.asyncio import Redis

from ai_service.application.contracts.events import (
    EmailReceived,
    EmailReviewResumed,
    InvalidEventError,
    LLMCallUsage,
)
from ai_service.application.errors import AgentError, ThreadMismatchError
from ai_service.application.mappers import failed_event, usage_from_calls
from ai_service.application.ports import EventBusPort
from ai_service.application.use_cases.process_email import ProcessingResult
from ai_service.infrastructure.checkpoints.postgres import PostgresCheckpoints
from ai_service.infrastructure.events.redis_streams import RedisStreamConsumer, StreamMessage
from ai_service.infrastructure.observability.logging import bind_context, clear_context, get_logger
from ai_service.infrastructure.observability.metrics import ServiceMetrics

log = get_logger(__name__)

IncomingEvent = EmailReceived | EmailReviewResumed


class UseCase(Protocol):
    async def execute(self, event: Any) -> ProcessingResult: ...


@dataclass(frozen=True, slots=True)
class ConsumerOptions:
    concurrency: int = 4
    read_block_ms: int = 5000
    claim_idle_ms: int = 300_000
    max_deliveries: int = 3
    retention_hours: int = 168
    processed_ttl_seconds: int = 86_400
    maintenance_every_seconds: float = 60.0
    checkpoint_ttl_hours: int = 336
    # Consumidores del grupo sin pendientes y sin leer desde hace más de esto:
    # pods que ya no existen (en Kubernetes cada pod tiene un nombre nuevo).
    prune_consumers_idle_ms: int = 3_600_000


class EventConsumer:
    def __init__(
        self,
        stream: RedisStreamConsumer,
        use_case: UseCase,
        bus: EventBusPort,
        metrics: ServiceMetrics,
        options: ConsumerOptions,
        checkpoints: PostgresCheckpoints | None = None,
        event_model: type[IncomingEvent] = EmailReceived,
    ) -> None:
        self._stream = stream
        self._event_model = event_model
        self._checkpoints = checkpoints
        self._use_case = use_case
        self._bus = bus
        self._metrics = metrics
        self._options = options
        self.last_heartbeat = 0.0

    @property
    def _redis(self) -> Redis:
        return self._stream.redis

    async def run(self, stop: asyncio.Event) -> None:
        await self._stream.ensure_group()
        log.info(
            "consumer_started",
            stream=self._stream.stream,
            group=self._stream.group,
            consumer=self._stream.consumer,
        )
        last_maintenance = 0.0
        while not stop.is_set():
            self._beat()
            try:
                batch = await self.poll()
            except Exception:  # Redis caído: se espera y se reintenta, sin salir
                log.exception("consumer_poll_failed")
                await _sleep_or_stop(stop, 2.0)
                continue
            if batch:
                await asyncio.gather(*(self.handle(m) for m in batch))
            if time.monotonic() - last_maintenance >= self._options.maintenance_every_seconds:
                last_maintenance = time.monotonic()
                await self._maintenance()
        log.info("consumer_stopped")

    async def poll(self) -> list[StreamMessage]:
        """Primero lo abandonado (reintentos), luego lo nuevo; como mucho `concurrency`."""
        size = self._options.concurrency
        batch = await self._stream.claim_abandoned(self._options.claim_idle_ms, size)
        if len(batch) < size:
            block = 1 if batch else self._options.read_block_ms
            batch += await self._stream.read(size - len(batch), block)
        return batch

    async def handle(self, message: StreamMessage) -> None:
        started = time.perf_counter()
        result = await self._handle(message)
        self._metrics.events.labels(result).inc()
        self._metrics.event_duration.labels(result).observe(time.perf_counter() - started)

    async def _handle(self, message: StreamMessage) -> str:
        if message.event_id and await self._redis.exists(self._processed_key(message.event_id)):
            await self._stream.ack(message)
            log.info("event_duplicate", event_id=message.event_id)
            return "duplicate"

        try:
            received = self._event_model.parse(message.payload)
        except InvalidEventError as error:
            await self._stream.dead_letter(message, str(error))
            log.warning("event_invalid", message_id=message.message_id, error=str(error)[:300])
            return "invalid"

        bind_context(
            trace_id=received.trace_id,
            tenant_id=received.tenant_id,
            email_id=received.email_id,
            event_id=str(received.event_id),
        )
        try:
            return await self._process(message, received)
        finally:
            clear_context()

    async def _process(self, message: StreamMessage, received: IncomingEvent) -> str:
        last_attempt = message.deliveries >= self._options.max_deliveries
        try:
            result = await self._use_case.execute(received)
        except ThreadMismatchError as error:
            await self._stream.dead_letter(message, str(error))
            log.critical("resume_rejected", error=str(error))
            return "rejected"
        except AgentError as error:
            self._record_llm_calls(usage_from_calls(error.calls).calls)
            if not last_attempt:
                log.warning("event_retry", deliveries=message.deliveries, error=error.cause.message)
                return "retry"
            await self._bus.publish(
                failed_event(
                    received,
                    error,
                    f"No se pudo procesar tras {message.deliveries} intentos: "
                    f"{error.cause.message}",
                )
            )
            await self._done(message, received)
            log.error("event_failed_after_retries", deliveries=message.deliveries)
            return "failed"
        except Exception:
            log.exception("event_unexpected_error", deliveries=message.deliveries)
            if not last_attempt:
                return "retry"
            try:
                await self._bus.publish(
                    failed_event(received, None, "Error interno al procesar el email.")
                )
            finally:
                await self._stream.dead_letter(message, "Error interno tras varios intentos.")
            return "dead_lettered"

        await self._done(message, received)
        self._record(result)
        return result.outcome

    async def _done(self, message: StreamMessage, received: IncomingEvent) -> None:
        await self._redis.set(
            self._processed_key(str(received.event_id)),
            "1",
            ex=self._options.processed_ttl_seconds,
        )
        await self._stream.ack(message)

    def _record(self, result: ProcessingResult) -> None:
        event = result.event
        self._record_llm_calls(event.usage.calls)
        if event.event_type == "email.processed":
            self._metrics.decisions.labels(event.decision, event.category.value, event.mode).inc()
            log.info(
                "email_processed",
                decision=event.decision,
                category=event.category.value,
                confidence=event.confidence,
                llm_calls=len(event.usage.calls),
            )
        else:
            log.warning("email_processing_failed", error_code=event.error_code)

    def _record_llm_calls(self, calls: Iterable[LLMCallUsage]) -> None:
        """Llamadas y tokens (también los de intentos fallidos: se facturan)."""
        for call in calls:
            self._metrics.llm_calls.labels(call.purpose, call.model, call.status).inc()
            for kind, value in (
                ("input", call.input_tokens),
                ("output", call.output_tokens),
                ("cache_write", call.cache_write_tokens),
                ("cache_read", call.cache_read_tokens),
            ):
                if value:
                    self._metrics.llm_tokens.labels(call.purpose, call.model, kind).inc(value)
            if call.latency_ms:
                self._metrics.llm_latency.labels(call.purpose, call.model).observe(
                    call.latency_ms / 1000
                )

    async def _maintenance(self) -> None:
        try:
            await self._stream.trim(self._options.retention_hours)
            pending, lag = await self._stream.lag()
            self._metrics.stream_pending.set(pending)
            self._metrics.stream_lag.set(lag)
            pruned = await self._stream.prune_consumers(self._options.prune_consumers_idle_ms)
            if pruned:
                log.info("stream_consumers_pruned", consumers=pruned)
            if self._checkpoints is not None:
                # Acciones que nadie aprobó ni rechazó a tiempo (datos personales).
                await self._checkpoints.purge(self._options.checkpoint_ttl_hours)
        except Exception:
            log.exception("consumer_maintenance_failed")

    def _beat(self) -> None:
        self.last_heartbeat = time.time()
        self._metrics.heartbeat.set(self.last_heartbeat)

    def _processed_key(self, event_id: str) -> str:
        return f"aiservice:processed:{event_id}"


async def _sleep_or_stop(stop: asyncio.Event, seconds: float) -> None:
    with contextlib.suppress(TimeoutError):
        await asyncio.wait_for(stop.wait(), timeout=seconds)
