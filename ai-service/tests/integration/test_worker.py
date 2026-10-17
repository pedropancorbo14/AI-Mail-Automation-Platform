import asyncio
import contextlib
import json

import httpx
import pytest
from pydantic import SecretStr
from redis.asyncio import Redis

from ai_service.config import Settings
from ai_service.entrypoints import worker
from ai_service.entrypoints.http.app import create_app
from tests.fakes import FakeAgent
from tests.integration.conftest import REDIS_URL
from tests.integration.test_redis_consumer import entries, outcome, publish_received


async def test_ready_reports_each_check() -> None:
    async def failing() -> dict[str, bool]:
        return {"redis": True, "consumer": False}

    app = create_app(Settings(_env_file=None), readiness=failing)
    async with httpx.AsyncClient(transport=httpx.ASGITransport(app=app), base_url="http://t") as c:
        response = await c.get("/ready")

    assert response.status_code == 503
    assert response.json() == {
        "status": "unavailable",
        "checks": {"redis": True, "consumer": False},
    }


async def test_the_worker_consumes_serves_ops_endpoints_and_stops(
    redis: Redis, monkeypatch: pytest.MonkeyPatch
) -> None:
    assert REDIS_URL
    settings = Settings(
        _env_file=None,
        redis_url=SecretStr(REDIS_URL),
        http_port=18765,
        read_block_ms=100,
        consumer_name="worker-test",
    )
    agent = FakeAgent(outcome=outcome())
    monkeypatch.setattr(worker, "build_agent", lambda _settings: agent)

    task = asyncio.create_task(worker.serve(settings))
    await publish_received(redis)
    async with httpx.AsyncClient(base_url="http://127.0.0.1:18765") as client:
        for _ in range(100):
            if await redis.xlen("email.processed.v1"):
                break
            await asyncio.sleep(0.05)
        ready = await client.get("/ready")
        metrics = (await client.get("/metrics")).text

    assert ready.status_code == 200
    assert ready.json()["checks"] == {"redis": True, "consumer": True}
    assert 'aiservice_events_total{result="processed"} 1.0' in metrics
    [entry] = await entries(redis, "email.processed.v1")
    assert json.loads(entry["payload"])["decision"] == "auto_send"

    task.cancel()  # en producción lo para SIGTERM (stop.set); aquí se cancela
    with contextlib.suppress(asyncio.CancelledError):
        await task
