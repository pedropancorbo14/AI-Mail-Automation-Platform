"""Redis Streams: publicar eventos y leerlos con consumer groups.

Formato de cada entrada: campos `event_id`, `event_type` y `payload` (el JSON
del contrato). Laravel escribe y lee igual.

Garantías: entrega **al menos una vez**. Un mensaje leído queda pendiente en el
grupo hasta que se confirma (`XACK`); si la réplica que lo tenía muere o falla
de forma temporal, otra lo reclama pasado `claim_idle_ms` (`XAUTOCLAIM`), lo que
sirve además de espera entre reintentos. Tras confirmarlo se borra (`XDEL`): el
cuerpo del email es dato personal y no debe quedarse en Redis.
"""

import time
from collections.abc import Sequence
from dataclasses import dataclass
from typing import Any

from redis.asyncio import Redis
from redis.exceptions import ResponseError

from ai_service.application.ports import OutgoingEvent

RECEIVED = "email.received.v1"
RESUMED = "email.review_resumed.v1"


def stream_for(event_type: str, version: int, prefix: str = "") -> str:
    return f"{prefix}{event_type}.v{version}"


@dataclass(frozen=True, slots=True)
class StreamMessage:
    message_id: str
    fields: dict[str, str]
    deliveries: int

    @property
    def payload(self) -> str:
        return self.fields.get("payload", "")

    @property
    def event_id(self) -> str | None:
        return self.fields.get("event_id")


def _decode(fields: dict[Any, Any]) -> dict[str, str]:
    return {
        (k.decode() if isinstance(k, bytes) else str(k)): (
            v.decode("utf-8", "replace") if isinstance(v, bytes) else str(v)
        )
        for k, v in fields.items()
    }


class RedisStreamsEventBus:
    """Implementa `EventBusPort`."""

    def __init__(self, redis: Redis, *, prefix: str = "", maxlen: int = 100_000) -> None:
        self._redis = redis
        self._prefix = prefix
        self._maxlen = maxlen

    async def publish(self, event: OutgoingEvent) -> None:
        stream = stream_for(event.event_type, event.event_version, self._prefix)
        await self._redis.xadd(
            stream,
            {
                "event_id": str(event.event_id),
                "event_type": event.event_type,
                "payload": event.to_json(),
            },
            maxlen=self._maxlen,
            approximate=True,
        )


class RedisStreamConsumer:
    """Primitivas del consumer group sobre un stream (las orquesta `entrypoints/consumer.py`)."""

    def __init__(
        self,
        redis: Redis,
        *,
        stream: str,
        group: str,
        consumer: str,
        dead_letter_stream: str | None = None,
    ) -> None:
        self.redis = redis
        self.stream = stream
        self.group = group
        self.consumer = consumer
        self.dead_letter_stream = dead_letter_stream or f"{stream}.dlq"

    async def ensure_group(self) -> None:
        """Crea el grupo (y el stream) si no existen. Desde el principio ("0"):
        lo publicado antes de que existiera el grupo también se procesa."""
        try:
            await self.redis.xgroup_create(self.stream, self.group, id="0", mkstream=True)
        except ResponseError as error:
            if "BUSYGROUP" not in str(error):
                raise

    async def read(self, count: int, block_ms: int) -> list[StreamMessage]:
        # Las respuestas de redis-py vienen poco tipadas: Any y se valida la forma.
        response: Any = await self.redis.xreadgroup(
            self.group, self.consumer, {self.stream: ">"}, count=count, block=block_ms
        )
        messages: list[StreamMessage] = []
        for _stream, entries in response or []:
            for message_id, fields in entries:
                messages.append(StreamMessage(_id(message_id), _decode(fields), deliveries=1))
        return messages

    async def claim_abandoned(self, min_idle_ms: int, count: int) -> list[StreamMessage]:
        """Reclama mensajes pendientes de cualquier consumidor que lleven más de
        `min_idle_ms` sin confirmar. Cada reclamación cuenta como una entrega más."""
        result = await self.redis.xautoclaim(
            self.stream, self.group, self.consumer, min_idle_time=min_idle_ms, count=count
        )
        entries = result[1] if len(result) > 1 else []
        claimed = [(_id(mid), fields) for mid, fields in entries if fields]
        if not claimed:
            return []
        deliveries = await self._deliveries([mid for mid, _ in claimed])
        return [
            StreamMessage(mid, _decode(fields), deliveries.get(mid, 1)) for mid, fields in claimed
        ]

    async def _deliveries(self, ids: Sequence[str]) -> dict[str, int]:
        found: dict[str, int] = {}
        for mid in ids:
            rows = await self.redis.xpending_range(self.stream, self.group, mid, mid, 1)
            for row in rows:
                found[_id(row["message_id"])] = int(row["times_delivered"])
        return found

    async def ack(self, message: StreamMessage) -> None:
        async with self.redis.pipeline(transaction=True) as pipe:
            pipe.xack(self.stream, self.group, message.message_id)
            pipe.xdel(self.stream, message.message_id)
            await pipe.execute()

    async def dead_letter(self, message: StreamMessage, reason: str) -> None:
        """Copia el mensaje a la cola de errores (para inspeccionarlo) y lo confirma."""
        fields: dict[Any, Any] = {
            **message.fields,
            "reason": reason[:500],
            "original_id": message.message_id,
            "deliveries": str(message.deliveries),
        }
        await self.redis.xadd(
            self.dead_letter_stream,
            fields,
            maxlen=10_000,
            approximate=True,
        )
        await self.ack(message)

    async def trim(self, retention_hours: int) -> None:
        """Borra lo más antiguo que la retención (ids de Redis = milisegundos).
        Exacto, no aproximado (`~` solo borra nodos internos completos y podría
        dejar datos personales más allá del plazo)."""
        min_id = int((time.time() - retention_hours * 3600) * 1000)
        for stream in (self.stream, self.dead_letter_stream):
            await self.redis.xtrim(stream, minid=f"{min_id}-0", approximate=False)

    async def prune_consumers(self, max_idle_ms: int) -> list[str]:
        """Borra del grupo los consumidores muertos: sin mensajes pendientes y
        más de `max_idle_ms` sin leer (uno vivo lee cada pocos segundos). En
        Kubernetes cada pod es un consumidor con nombre nuevo y Redis no los
        olvida nunca. Los pendientes de uno caído los reclama antes otra réplica
        (`claim_abandoned`), así que nunca se borra uno con trabajo sin terminar."""
        try:
            consumers = await self.redis.xinfo_consumers(self.stream, self.group)
        except ResponseError:
            return []
        pruned: list[str] = []
        for consumer in consumers:
            name = _id(consumer.get("name"))
            dead = int(consumer.get("pending") or 0) == 0 and int(consumer.get("idle") or 0) > (
                max_idle_ms
            )
            if dead and name != self.consumer:
                await self.redis.xgroup_delconsumer(self.stream, self.group, name)
                pruned.append(name)
        return pruned

    async def lag(self) -> tuple[int, int]:
        """(pendientes sin confirmar, entradas aún no leídas por el grupo)."""
        try:
            groups = await self.redis.xinfo_groups(self.stream)
        except ResponseError:
            return 0, 0
        for group in groups:
            name = group.get("name")
            if (name.decode() if isinstance(name, bytes) else name) == self.group:
                return int(group.get("pending") or 0), int(group.get("lag") or 0)
        return 0, 0


def _id(value: Any) -> str:
    return value.decode() if isinstance(value, bytes) else str(value)
