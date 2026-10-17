import os
from collections.abc import AsyncIterator

import pytest
from redis.asyncio import Redis

REDIS_URL = os.environ.get("AI_SERVICE_TEST_REDIS_URL")


@pytest.fixture
async def redis() -> AsyncIterator[Redis]:
    """Redis real (el CI lo levanta como servicio). Base de datos de test vaciada
    antes de cada test. Sin AI_SERVICE_TEST_REDIS_URL, estos tests se saltan."""
    if not REDIS_URL:
        pytest.skip("Define AI_SERVICE_TEST_REDIS_URL (p. ej. redis://127.0.0.1:6379/15)")
    client = Redis.from_url(REDIS_URL, decode_responses=False)
    await client.flushdb()
    yield client
    await client.flushdb()
    await client.aclose()
