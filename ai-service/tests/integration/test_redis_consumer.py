"""Consumidor y bus contra un Redis real: consumer group, confirmaciones,
reintentos con XAUTOCLAIM, cola de errores, duplicados y recorte."""

import asyncio
import json
import time
from typing import Any

from redis.asyncio import Redis

from ai_service.application.contracts.events import EmailReceived
from ai_service.application.dto import AgentOutcome
from ai_service.application.errors import (
    AgentError,
    LLMRefusalError,
    LLMUnavailableError,
)
from ai_service.application.use_cases.process_email import ProcessEmail
from ai_service.domain.decision import Decision, DecisionOutcome
from ai_service.domain.email import Email
from ai_service.entrypoints.consumer import ConsumerOptions, EventConsumer
from ai_service.infrastructure.events.redis_streams import (
    RECEIVED,
    RedisStreamConsumer,
    RedisStreamsEventBus,
)
from ai_service.infrastructure.observability.metrics import ServiceMetrics
from tests.fakes import FakeAgent, make_call
from tests.support.contracts import assert_matches_contract, example
from tests.unit.domain.factories import make_classification, make_draft

PROCESSED = "email.processed.v1"
FAILED = "email.processing_failed.v1"


def outcome() -> AgentOutcome:
    return AgentOutcome(
        classification=make_classification(),
        draft=make_draft(),
        outcome=DecisionOutcome(Decision.AUTO_SEND),
        calls=(make_call(), make_call("ai_service.draft", output_tokens=300)),
    )


def build(
    redis: Redis, agent: FakeAgent, **options: Any
) -> tuple[EventConsumer, RedisStreamConsumer, ServiceMetrics]:
    bus = RedisStreamsEventBus(redis)
    stream = RedisStreamConsumer(redis, stream=RECEIVED, group="ai-service", consumer="test-1")
    metrics = ServiceMetrics("testing")
    defaults: dict[str, Any] = {"read_block_ms": 100, "claim_idle_ms": 60_000, "max_deliveries": 3}
    consumer = EventConsumer(
        stream, ProcessEmail(agent, bus), bus, metrics, ConsumerOptions(**(defaults | options))
    )
    return consumer, stream, metrics


async def publish_received(redis: Redis, payload: dict[str, Any] | str | None = None) -> str:
    data = payload if payload is not None else example("email.received.v1")
    raw = data if isinstance(data, str) else json.dumps(data)
    event_id = data.get("event_id", "") if isinstance(data, dict) else ""
    message_id = await redis.xadd(RECEIVED, {"event_id": event_id, "payload": raw})
    return message_id.decode() if isinstance(message_id, bytes) else str(message_id)


async def entries(redis: Redis, stream: str) -> list[dict[str, Any]]:
    rows: Any = await redis.xrange(stream)
    return [{k.decode(): v.decode() for k, v in fields.items()} for _, fields in rows]


def count(metrics: ServiceMetrics, result: str) -> float:
    value = metrics.registry.get_sample_value("aiservice_events_total", {"result": result})
    return value or 0.0


async def drain(consumer: EventConsumer) -> int:
    batch = await consumer.poll()
    await asyncio.gather(*(consumer.handle(m) for m in batch))
    return len(batch)


async def test_a_received_email_produces_a_processed_event_and_is_removed(redis: Redis) -> None:
    agent = FakeAgent(outcome=outcome())
    consumer, stream, metrics = build(redis, agent)
    await stream.ensure_group()
    await publish_received(redis)

    assert await drain(consumer) == 1

    [processed] = await entries(redis, PROCESSED)
    payload = json.loads(processed["payload"])
    assert processed["event_type"] == "email.processed"
    assert (payload["tenant_id"], payload["email_id"]) == (1, 42)
    assert payload["decision"] == "auto_send"
    assert_matches_contract("email.processed.v1", payload)
    assert await redis.xlen(RECEIVED) == 0  # confirmado y borrado (dato personal)
    assert await stream.lag() == (0, 0)
    assert count(metrics, "processed") == 1
    tokens = metrics.registry.get_sample_value(
        "aiservice_llm_tokens_total",
        {"purpose": "ai_service.draft", "model": "fake-model", "type": "output"},
    )
    assert tokens == 300


async def test_the_group_processes_messages_published_before_it_existed(redis: Redis) -> None:
    await publish_received(redis)  # Laravel publicó antes de arrancar el servicio
    consumer, stream, _ = build(redis, FakeAgent(outcome=outcome()))
    await stream.ensure_group()
    await stream.ensure_group()  # idempotente

    assert await drain(consumer) == 1


async def test_invalid_messages_go_to_the_dead_letter_stream(redis: Redis) -> None:
    agent = FakeAgent(outcome=outcome())
    consumer, stream, metrics = build(redis, agent)
    await stream.ensure_group()
    payload = example("email.received.v1")
    del payload["tenant_id"]
    await publish_received(redis, payload)

    await drain(consumer)

    [dead] = await entries(redis, f"{RECEIVED}.dlq")
    assert "tenant_id" in dead["reason"]
    assert agent.seen == []  # nunca se procesa un email sin empresa
    assert await redis.xlen(RECEIVED) == 0
    assert count(metrics, "invalid") == 1


async def test_a_repeated_delivery_is_not_processed_twice(redis: Redis) -> None:
    agent = FakeAgent(outcome=outcome())
    consumer, stream, metrics = build(redis, agent)
    await stream.ensure_group()
    await publish_received(redis)
    await drain(consumer)

    await publish_received(redis)  # mismo event_id (p. ej. el reconciliador de Laravel)
    await drain(consumer)

    assert len(agent.seen) == 1
    assert len(await entries(redis, PROCESSED)) == 1
    assert count(metrics, "duplicate") == 1


async def test_definitive_errors_publish_a_failure(redis: Redis) -> None:
    agent = FakeAgent(error=AgentError(LLMRefusalError("El modelo se negó."), (make_call(),)))
    consumer, stream, metrics = build(redis, agent)
    await stream.ensure_group()
    await publish_received(redis)

    await drain(consumer)

    [failed] = await entries(redis, FAILED)
    payload = json.loads(failed["payload"])
    assert payload["error_code"] == "refusal"
    assert_matches_contract("email.processing_failed.v1", payload)
    assert await redis.xlen(RECEIVED) == 0
    assert count(metrics, "failed") == 1


async def test_temporary_errors_are_retried_and_finally_reported(redis: Redis) -> None:
    error = AgentError(LLMUnavailableError("529 sobrecargado"), (make_call(status="failed"),))
    agent = FakeAgent(error=error)
    consumer, stream, metrics = build(redis, agent, claim_idle_ms=1, max_deliveries=3)
    await stream.ensure_group()
    await publish_received(redis)

    await drain(consumer)  # 1ª entrega: queda pendiente
    assert await redis.xlen(FAILED) == 0
    assert (await stream.lag())[0] == 1
    await asyncio.sleep(0.01)
    await drain(consumer)  # 2ª (reclamada con XAUTOCLAIM)
    await asyncio.sleep(0.01)
    await drain(consumer)  # 3ª y última: se publica el fallo

    assert len(agent.seen) == 3
    assert count(metrics, "retry") == 2
    [failed] = await entries(redis, FAILED)
    payload = json.loads(failed["payload"])
    assert payload["error_code"] == "timeout"
    assert payload["message"].startswith("No se pudo procesar tras 3 intentos")
    assert (await stream.lag())[0] == 0


async def test_unexpected_errors_end_in_the_dead_letter_stream(redis: Redis) -> None:
    class BrokenAgent(FakeAgent):
        async def run(self, email: Email) -> AgentOutcome:
            self.seen.append(email)
            raise RuntimeError("bug")

    consumer, stream, metrics = build(redis, BrokenAgent(), claim_idle_ms=1, max_deliveries=2)
    await stream.ensure_group()
    await publish_received(redis)

    await drain(consumer)
    await asyncio.sleep(0.01)
    await drain(consumer)

    assert count(metrics, "dead_lettered") == 1
    [failed] = await entries(redis, FAILED)
    assert json.loads(failed["payload"])["error_code"] == "internal"
    [dead] = await entries(redis, f"{RECEIVED}.dlq")
    assert dead["deliveries"] == "2"


async def test_several_emails_are_processed_concurrently(redis: Redis) -> None:
    agent = FakeAgent(outcome=outcome())
    consumer, stream, _ = build(redis, agent, concurrency=3)
    await stream.ensure_group()
    for email_id in (1, 2, 3):
        payload = example("email.received.v1") | {"email_id": email_id}
        payload["event_id"] = f"00000000-0000-4000-8000-00000000000{email_id}"
        await publish_received(redis, payload)

    assert await drain(consumer) == 3

    ids = {json.loads(e["payload"])["email_id"] for e in await entries(redis, PROCESSED)}
    assert ids == {1, 2, 3}


async def test_each_result_keeps_the_tenant_of_its_email(redis: Redis) -> None:
    agent = FakeAgent(outcome=outcome())
    consumer, stream, _ = build(redis, agent, concurrency=2)
    await stream.ensure_group()
    for tenant_id, email_id in ((1, 10), (2, 20)):
        payload = example("email.received.v1") | {"tenant_id": tenant_id, "email_id": email_id}
        payload["event_id"] = f"00000000-0000-4000-8000-0000000000{email_id}"
        await publish_received(redis, payload)

    await drain(consumer)

    pairs = {
        (json.loads(e["payload"])["tenant_id"], json.loads(e["payload"])["email_id"])
        for e in await entries(redis, PROCESSED)
    }
    assert pairs == {(1, 10), (2, 20)}
    assert {e.tenant_id.value for e in agent.seen} == {1, 2}


async def test_old_entries_are_trimmed(redis: Redis) -> None:
    _, stream, _ = build(redis, FakeAgent())
    old = int((time.time() - 10 * 3600) * 1000)
    await redis.xadd(RECEIVED, {"payload": "{}"}, id=f"{old}-0")
    await redis.xadd(RECEIVED, {"payload": "{}"})

    await stream.trim(retention_hours=1)

    assert await redis.xlen(RECEIVED) == 1


async def test_the_loop_stops_cleanly(redis: Redis) -> None:
    agent = FakeAgent(outcome=outcome())
    consumer, _, _ = build(redis, agent)
    stop = asyncio.Event()
    task = asyncio.create_task(consumer.run(stop))
    await publish_received(redis)

    for _ in range(50):
        if await redis.xlen(PROCESSED):
            break
        await asyncio.sleep(0.05)
    stop.set()
    await asyncio.wait_for(task, timeout=2)

    assert await redis.xlen(PROCESSED) == 1
    assert consumer.last_heartbeat > 0


async def test_bus_publishes_on_the_versioned_stream(redis: Redis) -> None:
    event = EmailReceived.parse(example("email.received.v1"))
    bus = RedisStreamsEventBus(redis, prefix="test:")
    consumer = FakeAgent(outcome=outcome())
    result = await ProcessEmail(consumer, bus).execute(event)

    [entry] = await entries(redis, "test:email.processed.v1")
    assert entry["event_id"] == str(result.event.event_id)
