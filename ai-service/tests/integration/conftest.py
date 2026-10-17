import os
from collections.abc import AsyncIterator
from pathlib import Path
from urllib.parse import urlsplit, urlunsplit

import pytest
from redis.asyncio import Redis
from sqlalchemy import text
from sqlalchemy.ext.asyncio import AsyncEngine, create_async_engine

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


DATABASE_URL = os.environ.get("AI_SERVICE_TEST_DATABASE_URL")
READER_PASSWORD = "reader-secret"
ROOT = Path(__file__).parents[3]


def reader_url(admin_url: str) -> str:
    """Misma base de datos, pero con el rol de solo lectura ai_service_reader."""
    parts = urlsplit(admin_url)
    host = parts.hostname or "localhost"
    netloc = f"ai_service_reader:{READER_PASSWORD}@{host}:{parts.port or 5432}"
    return urlunsplit((parts.scheme, netloc, parts.path, parts.query, parts.fragment))


@pytest.fixture
async def knowledge_db() -> AsyncIterator[tuple[AsyncEngine, str]]:
    """PostgreSQL + pgvector real con el esquema que lee ai-service, el rol
    ai_service_reader y los permisos/RLS de contracts/database/ (los mismos que
    aplica la migración de Laravel). Devuelve (motor admin, URL del lector).
    Sin AI_SERVICE_TEST_DATABASE_URL, estos tests se saltan."""
    if not DATABASE_URL:
        pytest.skip(
            "Define AI_SERVICE_TEST_DATABASE_URL "
            "(p. ej. postgresql+psycopg://app:secret@127.0.0.1:5432/ai_service_test)"
        )
    admin = create_async_engine(DATABASE_URL)
    schema = (Path(__file__).parents[1] / "fixtures" / "knowledge_schema.sql").read_text()
    access = (ROOT / "contracts" / "database" / "knowledge_base_reader.sql").read_text()
    async with admin.begin() as conn:
        await conn.exec_driver_sql(schema)
        await conn.exec_driver_sql(
            "DO $$ BEGIN CREATE ROLE ai_service_reader LOGIN PASSWORD "
            f"'{READER_PASSWORD}' NOINHERIT; EXCEPTION WHEN duplicate_object THEN NULL; END $$;"
        )
        await conn.exec_driver_sql(access)
    yield admin, reader_url(DATABASE_URL)
    async with admin.begin() as conn:
        await conn.execute(text("DROP TABLE IF EXISTS document_chunks, documents"))
    await admin.dispose()


CHECKPOINTS_PASSWORD = "checkpoints-secret"


def role_url(admin_url: str, role: str, password: str) -> str:
    """Misma base de datos con otro rol."""
    parts = urlsplit(admin_url)
    netloc = f"{role}:{password}@{parts.hostname or 'localhost'}:{parts.port or 5432}"
    return urlunsplit((parts.scheme, netloc, parts.path, parts.query, parts.fragment))


@pytest.fixture
async def checkpoint_db() -> AsyncIterator[tuple[AsyncEngine, str]]:
    """PostgreSQL real con el rol ai_service_checkpoints y su esquema creados con
    contracts/database/ai_service_checkpoints.sql (lo mismo que la migración de
    Laravel). Devuelve (motor admin, URL del rol). El esquema se borra al acabar."""
    if not DATABASE_URL:
        pytest.skip(
            "Define AI_SERVICE_TEST_DATABASE_URL "
            "(p. ej. postgresql+psycopg://app:secret@127.0.0.1:5432/ai_service_test)"
        )
    admin = create_async_engine(DATABASE_URL)
    setup = (ROOT / "contracts" / "database" / "ai_service_checkpoints.sql").read_text()
    async with admin.begin() as conn:
        await conn.exec_driver_sql("DROP SCHEMA IF EXISTS ai_service CASCADE")
        await conn.exec_driver_sql(
            "DO $$ BEGIN CREATE ROLE ai_service_checkpoints LOGIN PASSWORD "
            f"'{CHECKPOINTS_PASSWORD}' NOINHERIT; "
            "EXCEPTION WHEN duplicate_object THEN NULL; END $$;"
        )
        await conn.exec_driver_sql(setup)
    yield admin, role_url(DATABASE_URL, "ai_service_checkpoints", CHECKPOINTS_PASSWORD)
    async with admin.begin() as conn:
        await conn.exec_driver_sql("DROP SCHEMA IF EXISTS ai_service CASCADE")
    await admin.dispose()
