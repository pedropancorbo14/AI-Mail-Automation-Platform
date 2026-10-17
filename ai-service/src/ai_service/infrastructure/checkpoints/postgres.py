"""Checkpoints de LangGraph en PostgreSQL (esquema `ai_service`, rol propio).

Guardan el estado del grafo cuando queda en pausa esperando que una persona
apruebe una acción (Fase 4). El rol `ai_service_checkpoints` solo puede usar
su esquema (`contracts/database/ai_service_checkpoints.sql`): nada de las
tablas de Laravel.

Las tablas las crea `AsyncPostgresSaver.setup()` (idempotente, con sus
propias migraciones). Los hilos llevan datos personales (el email): se
borran al terminar y los que nadie reanuda se purgan pasado un tiempo.
"""

from typing import Self

from langgraph.checkpoint.postgres.aio import AsyncPostgresSaver
from psycopg import AsyncConnection
from psycopg.rows import DictRow, dict_row
from psycopg_pool import AsyncConnectionPool

from ai_service.infrastructure.observability.logging import get_logger

log = get_logger(__name__)

SCHEMA = "ai_service"

# Hilos cuyo último checkpoint es anterior al límite.
_EXPIRED_THREADS = """
    SELECT thread_id
    FROM checkpoints
    GROUP BY thread_id
    HAVING max((checkpoint->>'ts')::timestamptz) < now() - make_interval(hours => %s)
"""


def conninfo(url: str) -> str:
    """La URL de la configuración es de SQLAlchemy (`postgresql+psycopg://`)."""
    return url.replace("postgresql+psycopg://", "postgresql://", 1)


class PostgresCheckpoints:
    def __init__(self, pool: AsyncConnectionPool[AsyncConnection[DictRow]]) -> None:
        self._pool = pool
        self.saver = AsyncPostgresSaver(pool)

    @classmethod
    async def open(cls, url: str, *, max_size: int = 5, connect_timeout: float = 10.0) -> Self:
        pool: AsyncConnectionPool[AsyncConnection[DictRow]] = AsyncConnectionPool(
            conninfo(url),
            open=False,
            max_size=max_size,
            timeout=connect_timeout,
            # Lo que exige langgraph-checkpoint-postgres, y su esquema aunque el rol
            # no tuviera search_path por defecto.
            kwargs={
                "autocommit": True,
                "prepare_threshold": 0,
                "row_factory": dict_row,
                "options": f"-c search_path={SCHEMA}",
            },
        )
        await pool.open(wait=True, timeout=connect_timeout)
        checkpoints = cls(pool)
        await checkpoints.saver.setup()
        return checkpoints

    async def delete(self, thread_id: str) -> None:
        await self.saver.adelete_thread(thread_id)

    async def purge(self, older_than_hours: int) -> int:
        """Borra los hilos en pausa que nadie reanudó a tiempo. Devuelve cuántos."""
        async with self._pool.connection() as conn:
            rows = await (await conn.execute(_EXPIRED_THREADS, (older_than_hours,))).fetchall()
        for row in rows:
            await self.saver.adelete_thread(row["thread_id"])
        if rows:
            log.info("checkpoints_purged", threads=len(rows), older_than_hours=older_than_hours)
        return len(rows)

    async def ping(self) -> bool:
        try:
            async with self._pool.connection() as conn:
                await conn.execute("SELECT 1")
        except Exception:
            return False
        return True

    async def aclose(self) -> None:
        await self._pool.close()
