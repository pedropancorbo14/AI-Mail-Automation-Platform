"""Reanudaciones (`email.review_resumed.v1`) contra Redis real, y el worker
completo: Redis + checkpoints en PostgreSQL + servidor MCP + IA simulada."""

import asyncio
import contextlib
import json
from typing import Any

import httpx
from pydantic import SecretStr
from redis.asyncio import Redis

from ai_service.application.contracts.events import EmailReviewResumed
from ai_service.application.dto import AgentOutcome
from ai_service.application.errors import ThreadMismatchError
from ai_service.application.use_cases.resume_review import ResumeReview
from ai_service.config import Environment, Settings
from ai_service.domain.email import TenantId
from ai_service.entrypoints import worker
from ai_service.entrypoints.consumer import ConsumerOptions, EventConsumer
from ai_service.infrastructure.events.redis_streams import (
    RECEIVED,
    RESUMED,
    RedisStreamConsumer,
    RedisStreamsEventBus,
)
from ai_service.infrastructure.observability.metrics import ServiceMetrics
from tests.fakes import FakeAgent
from tests.integration.conftest import REDIS_URL
from tests.integration.test_mcp_server import TOKEN, running
from tests.integration.test_redis_consumer import FAILED, PROCESSED, count, entries, outcome
from tests.support.contracts import example


def build(
    redis: Redis, agent: FakeAgent
) -> tuple[EventConsumer, RedisStreamConsumer, ServiceMetrics]:
    bus = RedisStreamsEventBus(redis)
    stream = RedisStreamConsumer(redis, stream=RESUMED, group="ai-service", consumer="test-1")
    metrics = ServiceMetrics("testing")
    consumer = EventConsumer(
        stream,
        ResumeReview(agent, bus),
        bus,
        metrics,
        ConsumerOptions(read_block_ms=100, claim_idle_ms=60_000, max_deliveries=3),
        event_model=EmailReviewResumed,
    )
    return consumer, stream, metrics


async def publish(redis: Redis, stream: str, payload: dict[str, Any]) -> None:
    await redis.xadd(stream, {"event_id": payload["event_id"], "payload": json.dumps(payload)})


async def test_a_resume_event_is_applied_once(redis: Redis) -> None:
    agent = FakeAgent(outcome=outcome())
    consumer, stream, metrics = build(redis, agent)
    await stream.ensure_group()
    event = example("email.review_resumed.v1")
    await publish(redis, RESUMED, event)
    await publish(redis, RESUMED, event)  # entrega repetida (mismo event_id)

    for message in await consumer.poll():
        await consumer.handle(message)

    [processed] = await entries(redis, PROCESSED)
    assert json.loads(processed["payload"])["causation_id"] == event["event_id"]
    assert len(agent.resumes) == 1
    assert count(metrics, "duplicate") == 1
    assert (await stream.lag())[0] == 0


async def test_a_resume_for_something_else_goes_to_the_dead_letter_stream(redis: Redis) -> None:
    class Mismatch(FakeAgent):
        async def resume(
            self,
            thread_id: str,
            *,
            tenant_id: TenantId,
            email_id: int,
            action_id: str,
            approved: bool,
        ) -> AgentOutcome:
            raise ThreadMismatchError("La reanudación no es de la empresa o el email de la pausa.")

    consumer, stream, metrics = build(redis, Mismatch())
    await stream.ensure_group()
    await publish(redis, RESUMED, example("email.review_resumed.v1"))

    for message in await consumer.poll():
        await consumer.handle(message)

    assert await entries(redis, PROCESSED) == []
    assert await entries(redis, FAILED) == []
    [dead] = await entries(redis, f"{RESUMED}.dlq")
    assert "no es de la empresa" in dead["reason"]
    assert count(metrics, "rejected") == 1


async def test_the_worker_waits_for_a_person_and_resumes_with_the_approval(
    redis: Redis, checkpoint_db: Any
) -> None:
    """De punta a punta en el worker real: soporte técnico de un cliente sin
    ticket → acción pendiente (en pausa) → aprobada → ticket abierto y respuesta
    que lo cita."""
    assert REDIS_URL
    _, checkpoints_url = checkpoint_db
    async with running(18128) as mcp_url:
        settings = Settings(
            _env_file=None,
            environment=Environment.TESTING,
            llm_provider="simulated",
            redis_url=SecretStr(REDIS_URL),
            http_port=18766,
            read_block_ms=100,
            consumer_name="worker-approval",
            mcp_url=mcp_url,
            mcp_token=SecretStr(TOKEN),
            checkpoint_database_url=SecretStr(checkpoints_url),
        )
        task = asyncio.create_task(worker.serve(settings))
        received = example("email.received.v1") | {
            "from": "marcos@cliente.test",
            "subject": "La lámpara no enciende",
            "body": "Hola, la lámpara que compré no enciende. ¿Qué hago?",
            # La empresa tiene activados los tickets (consultarlos y proponer abrir uno).
            "enabled_tools": ["create_support_ticket", "get_customer_tickets"],
        }
        try:
            await publish(redis, RECEIVED, received)
            [paused] = await wait_for(redis, PROCESSED, 1)
            action = paused["pending_action"]
            async with httpx.AsyncClient(base_url="http://127.0.0.1:18766") as client:
                ready = (await client.get("/ready")).json()

            await publish(
                redis,
                RESUMED,
                example("email.review_resumed.v1")
                | {"thread_id": paused["thread_id"], "action_id": action["action_id"]},
            )
            done = (await wait_for(redis, PROCESSED, 2))[1]
        finally:
            task.cancel()
            with contextlib.suppress(asyncio.CancelledError):
                await task

    assert ready["checks"] == {"redis": True, "consumer": True, "checkpoints": True}
    assert paused["decision"] == "human_review"
    assert paused["draft"] == ""
    assert action["tool"] == "create_support_ticket"
    assert action["arguments"]["subject"] == "La lámpara no enciende"
    ticket = done["tool_calls"][-1]["result"]["ticket"]["id"]
    assert ticket in done["draft"]
    assert done["decision"] == "auto_send"
    assert done["causation_id"] == example("email.review_resumed.v1")["event_id"]
    assert done.get("pending_action") is None
    # La clasificación ya se informó en la pausa: no se factura dos veces.
    assert [c["purpose"] for c in done["usage"]["calls"]] == ["ai_service.draft"]


async def wait_for(redis: Redis, stream: str, n: int) -> list[dict[str, Any]]:
    for _ in range(200):
        rows = await entries(redis, stream)
        if len(rows) >= n:
            return [json.loads(r["payload"]) for r in rows]
        await asyncio.sleep(0.05)
    raise AssertionError(f"{stream}: esperaba {n} eventos")
