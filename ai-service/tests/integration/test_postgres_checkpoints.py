"""Checkpoints de LangGraph en PostgreSQL real, con el rol y el esquema del
contrato (contracts/database/ai_service_checkpoints.sql)."""

import operator
from typing import Annotated, Any, TypedDict
from unittest.mock import AsyncMock

import pytest
from langgraph.graph import END, START, StateGraph
from langgraph.types import Command, interrupt
from psycopg import AsyncConnection, errors
from pydantic import SecretStr
from sqlalchemy import text
from sqlalchemy.ext.asyncio import AsyncEngine

from ai_service.config import Settings
from ai_service.entrypoints import worker
from ai_service.entrypoints.consumer import ConsumerOptions, EventConsumer
from ai_service.infrastructure.checkpoints.postgres import PostgresCheckpoints, conninfo
from ai_service.infrastructure.observability.metrics import ServiceMetrics

Db = tuple[AsyncEngine, str]


class State(TypedDict):
    steps: Annotated[list[str], operator.add]
    approved: bool


async def _ask(state: State) -> dict[str, Any]:
    # Lo que hará el grafo de emails: pausa hasta que una persona decida.
    approved = interrupt({"question": "¿Crear el ticket?"})
    return {"steps": ["ask"], "approved": bool(approved)}


def graph(checkpoints: PostgresCheckpoints) -> Any:
    builder = StateGraph(State)
    builder.add_node("ask", _ask)
    builder.add_edge(START, "ask")
    builder.add_edge("ask", END)
    return builder.compile(checkpointer=checkpoints.saver)


def thread(thread_id: str) -> Any:
    return {"configurable": {"thread_id": thread_id}}


async def test_setup_creates_its_tables_in_its_own_schema(checkpoint_db: Db) -> None:
    admin, url = checkpoint_db

    checkpoints = await PostgresCheckpoints.open(url)
    await checkpoints.saver.setup()  # idempotente: el worker lo llama en cada arranque
    await checkpoints.aclose()

    async with admin.connect() as conn:
        tables = set(
            (
                await conn.execute(
                    text(
                        "SELECT table_name FROM information_schema.tables "
                        "WHERE table_schema = 'ai_service'"
                    )
                )
            ).scalars()
        )
        in_public = (
            await conn.execute(
                text(
                    "SELECT count(*) FROM information_schema.tables "
                    "WHERE table_name = 'checkpoints' AND table_schema = 'public'"
                )
            )
        ).scalar()
    assert {
        "checkpoints",
        "checkpoint_blobs",
        "checkpoint_writes",
        "checkpoint_migrations",
    } <= tables
    assert in_public == 0


async def test_a_paused_graph_resumes_after_a_restart(checkpoint_db: Db) -> None:
    _, url = checkpoint_db
    first = await PostgresCheckpoints.open(url)
    paused = await graph(first).ainvoke(
        {"steps": [], "approved": False}, thread("t1-e42-abc"), durability="exit"
    )
    await first.aclose()  # el worker se reinicia mientras la persona decide

    assert paused["__interrupt__"][0].value == {"question": "¿Crear el ticket?"}

    second = await PostgresCheckpoints.open(url)
    done = await graph(second).ainvoke(Command(resume=True), thread("t1-e42-abc"))
    await second.aclose()

    assert done == {"steps": ["ask"], "approved": True}


async def test_delete_forgets_the_thread(checkpoint_db: Db) -> None:
    _, url = checkpoint_db
    checkpoints = await PostgresCheckpoints.open(url)
    await graph(checkpoints).ainvoke({"steps": [], "approved": False}, thread("t1-e42-del"))

    await checkpoints.delete("t1-e42-del")

    assert await checkpoints.saver.aget_tuple(thread("t1-e42-del")) is None
    await checkpoints.aclose()


async def test_purge_only_removes_threads_older_than_the_limit(checkpoint_db: Db) -> None:
    admin, url = checkpoint_db
    checkpoints = await PostgresCheckpoints.open(url)
    for thread_id in ("t1-e1-old", "t1-e2-new"):
        await graph(checkpoints).ainvoke({"steps": [], "approved": False}, thread(thread_id))
    async with admin.begin() as conn:
        await conn.execute(
            text(
                "UPDATE ai_service.checkpoints SET checkpoint = "
                "jsonb_set(checkpoint, '{ts}', to_jsonb((now() - interval '20 days')::text)) "
                "WHERE thread_id = 't1-e1-old'"
            )
        )

    purged = await checkpoints.purge(older_than_hours=24 * 14)

    assert purged == 1
    assert await checkpoints.saver.aget_tuple(thread("t1-e1-old")) is None
    assert await checkpoints.saver.aget_tuple(thread("t1-e2-new")) is not None
    assert await checkpoints.purge(older_than_hours=24 * 14) == 0
    await checkpoints.aclose()


@pytest.mark.parametrize(
    "sql",
    [
        "SELECT * FROM public.laravel_probe",
        "CREATE TABLE public.intruder (id int)",
        "INSERT INTO public.laravel_probe VALUES (2)",
    ],
)
async def test_the_role_cannot_touch_laravel_tables(checkpoint_db: Db, sql: str) -> None:
    admin, url = checkpoint_db
    async with admin.begin() as conn:
        await conn.exec_driver_sql("CREATE TABLE IF NOT EXISTS public.laravel_probe (id int)")
    try:
        async with await AsyncConnection.connect(conninfo(url)) as conn:
            with pytest.raises(errors.InsufficientPrivilege):
                await conn.execute(sql)
    finally:
        async with admin.begin() as conn:
            await conn.exec_driver_sql("DROP TABLE IF EXISTS public.laravel_probe, public.intruder")


async def test_ping(checkpoint_db: Db) -> None:
    _, url = checkpoint_db
    checkpoints = await PostgresCheckpoints.open(url)

    assert await checkpoints.ping()

    await checkpoints.aclose()
    assert not await checkpoints.ping()


async def test_the_worker_opens_checkpoints_only_when_configured(checkpoint_db: Db) -> None:
    _, url = checkpoint_db

    assert await worker.open_checkpoints(Settings(_env_file=None)) is None

    opened = await worker.open_checkpoints(
        Settings(_env_file=None, checkpoint_database_url=SecretStr(url))
    )
    assert opened is not None
    assert await opened.ping()
    await opened.aclose()


async def test_consumer_maintenance_purges_expired_threads(checkpoint_db: Db) -> None:
    admin, url = checkpoint_db
    checkpoints = await PostgresCheckpoints.open(url)
    await graph(checkpoints).ainvoke({"steps": [], "approved": False}, thread("t1-e9-old"))
    async with admin.begin() as conn:
        await conn.execute(
            text(
                "UPDATE ai_service.checkpoints SET checkpoint = "
                "jsonb_set(checkpoint, '{ts}', to_jsonb((now() - interval '3 days')::text))"
            )
        )
    stream = AsyncMock()
    stream.lag.return_value = (0, 0)
    consumer = EventConsumer(
        stream,
        AsyncMock(),
        AsyncMock(),
        ServiceMetrics("testing"),
        ConsumerOptions(checkpoint_ttl_hours=48),
        checkpoints,
    )

    await consumer._maintenance()

    assert await checkpoints.saver.aget_tuple(thread("t1-e9-old")) is None
    await checkpoints.aclose()
