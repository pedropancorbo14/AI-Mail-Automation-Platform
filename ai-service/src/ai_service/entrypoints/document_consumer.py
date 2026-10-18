"""Consumidor de los eventos de documentos (`document.uploaded.v1` y
`document.deleted.v1`): indexar y borrar fragmentos de la base de
conocimiento de ai-service.

Misma política que el de emails, más sencilla (sin modelo de lenguaje):
- Hecho → `XACK` + `XDEL` y `event_id` marcado como procesado (una entrega
  repetida no reindexa ni vuelve a borrar).
- No cumple el contrato → cola de errores (`.dlq`).
- Temporal (descarga, Ollama, PostgreSQL) → sin confirmar: se reintenta al
  reclamarlo; en la última entrega de un indexado se publica
  `document.indexing_failed.v1` para que el panel no se quede en "Indexando".
- Inesperado → igual; en la última entrega, fallo `internal` y cola de errores.
"""

import asyncio
import time

from ai_service.application.contracts.events import (
    DocumentDeleted,
    DocumentUploaded,
    InvalidEventError,
)
from ai_service.application.use_cases.index_document import (
    DeleteDocument,
    DocumentResult,
    DocumentTemporaryError,
    IndexDocument,
)
from ai_service.entrypoints.consumer import ConsumerOptions, _sleep_or_stop
from ai_service.infrastructure.events.redis_streams import RedisStreamConsumer, StreamMessage
from ai_service.infrastructure.observability.logging import bind_context, clear_context, get_logger
from ai_service.infrastructure.observability.metrics import ServiceMetrics

log = get_logger(__name__)

UPLOADED = "document.uploaded.v1"
DELETED = "document.deleted.v1"


class DocumentConsumer:
    def __init__(
        self,
        stream: RedisStreamConsumer,
        handler: IndexDocument | DeleteDocument,
        metrics: ServiceMetrics,
        options: ConsumerOptions,
    ) -> None:
        self._stream = stream
        self._handler = handler
        self._metrics = metrics
        self._options = options
        self.last_heartbeat = 0.0

    async def run(self, stop: asyncio.Event) -> None:
        await self._stream.ensure_group()
        log.info("consumer_started", stream=self._stream.stream, group=self._stream.group)
        last_maintenance = 0.0
        while not stop.is_set():
            self.last_heartbeat = time.time()
            try:
                batch = await self.poll()
            except Exception:  # Redis caído: se espera y se reintenta
                log.exception("consumer_poll_failed")
                await _sleep_or_stop(stop, 2.0)
                continue
            for message in batch:  # de uno en uno: indexar usa CPU de Ollama
                await self.handle(message)
            if time.monotonic() - last_maintenance >= self._options.maintenance_every_seconds:
                last_maintenance = time.monotonic()
                try:
                    await self._stream.trim(self._options.retention_hours)
                    await self._stream.prune_consumers(self._options.prune_consumers_idle_ms)
                except Exception:
                    log.exception("consumer_maintenance_failed")
        log.info("consumer_stopped", stream=self._stream.stream)

    async def poll(self) -> list[StreamMessage]:
        batch = await self._stream.claim_abandoned(self._options.claim_idle_ms, 1)
        if not batch:
            batch = await self._stream.read(1, self._options.read_block_ms)
        return batch

    async def handle(self, message: StreamMessage) -> str:
        result = await self._handle(message)
        self._metrics.events.labels(result).inc()
        return result

    async def _handle(self, message: StreamMessage) -> str:
        redis = self._stream.redis
        if message.event_id and await redis.exists(_processed(message.event_id)):
            await self._stream.ack(message)
            return "duplicate"
        model = DocumentUploaded if isinstance(self._handler, IndexDocument) else DocumentDeleted
        try:
            event = model.parse(message.payload)
        except InvalidEventError as error:
            await self._stream.dead_letter(message, str(error))
            log.warning("event_invalid", message_id=message.message_id, error=str(error)[:300])
            return "invalid"

        bind_context(
            trace_id=event.trace_id,
            tenant_id=event.tenant_id,
            document_id=event.document_id,
            event_id=str(event.event_id),
        )
        last_attempt = message.deliveries >= self._options.max_deliveries
        try:
            result: DocumentResult = await self._handler.execute(event)  # type: ignore[arg-type]
        except DocumentTemporaryError as error:
            if not last_attempt:
                log.warning("document_retry", deliveries=message.deliveries, error=str(error))
                return "retry"
            if isinstance(self._handler, IndexDocument) and isinstance(event, DocumentUploaded):
                await self._handler.give_up(
                    event,
                    f"No se pudo indexar tras {message.deliveries} intentos: {error}",
                    error.code,
                )
            await self._done(message)
            log.error("document_failed_after_retries", error=str(error))
            return "failed"
        except Exception:
            log.exception("document_unexpected_error", deliveries=message.deliveries)
            if not last_attempt:
                return "retry"
            if isinstance(self._handler, IndexDocument) and isinstance(event, DocumentUploaded):
                await self._handler.give_up(event, "Error interno al indexar el documento.")
            await self._stream.dead_letter(message, "Error interno tras varios intentos.")
            return "dead_lettered"
        finally:
            clear_context()

        await self._done(message)
        log.info("document_" + result.outcome)
        return result.outcome

    async def _done(self, message: StreamMessage) -> None:
        if message.event_id:
            await self._stream.redis.set(
                _processed(message.event_id), "1", ex=self._options.processed_ttl_seconds
            )
        await self._stream.ack(message)


def _processed(event_id: str) -> str:
    return f"aiservice:processed:{event_id}"
