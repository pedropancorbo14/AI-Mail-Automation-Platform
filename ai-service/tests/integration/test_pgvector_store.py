"""Búsqueda en pgvector con el rol de solo lectura y RLS reales."""

import json
import math
from collections.abc import Sequence
from typing import Any

import pytest
from sqlalchemy import text
from sqlalchemy.exc import ProgrammingError
from sqlalchemy.ext.asyncio import AsyncEngine, create_async_engine

from ai_service.application.errors import KnowledgeBaseError
from ai_service.domain.email import TenantId
from ai_service.infrastructure.knowledge.pgvector import PgVectorStore

DIMS = 1024


def unit(*hot: int) -> list[float]:
    """Vector normalizado con valor en las posiciones indicadas (parecido controlable)."""
    v = [0.0] * DIMS
    for i in hot:
        v[i] = 1.0
    norm = math.sqrt(sum(x * x for x in v))
    return [x / norm for x in v]


def literal(vector: Sequence[float]) -> str:
    return "[" + ",".join(str(x) for x in vector) + "]"


async def add_document(
    admin: AsyncEngine,
    tenant: int,
    title: str,
    chunks: Sequence[tuple[str, list[float], dict[str, Any]]],
    *,
    status: str = "ready",
    model: str = "bge-m3",
) -> int:
    async with admin.begin() as conn:
        doc_id = int(
            (
                await conn.execute(
                    text(
                        "INSERT INTO documents (tenant_id, title, status, embedding_model) "
                        "VALUES (:t, :title, :s, :m) RETURNING id"
                    ),
                    {"t": tenant, "title": title, "s": status, "m": model},
                )
            ).scalar_one()
        )
        for position, (content, vector, metadata) in enumerate(chunks):
            await conn.execute(
                text(
                    "INSERT INTO document_chunks "
                    "(tenant_id, document_id, position, content, metadata, embedding) "
                    "VALUES (:t, :d, :p, :c, CAST(:meta AS jsonb), CAST(:v AS vector))"
                ),
                {
                    "t": tenant,
                    "d": doc_id,
                    "p": position,
                    "c": content,
                    "meta": json.dumps(metadata),
                    "v": literal(vector),
                },
            )
    return doc_id


async def run_as_reader(reader: str, sql: str) -> None:
    engine = create_async_engine(reader)
    try:
        async with engine.begin() as conn:
            await conn.execute(text("SELECT set_config('app.tenant_id', '1', true)"))
            await conn.execute(text(sql))
    finally:
        await engine.dispose()


async def search(reader: str, tenant: int, vector: list[float], **kwargs: Any) -> list[Any]:
    store = PgVectorStore.create(reader)
    try:
        options: dict[str, Any] = {"embedding_model": "bge-m3", "limit": 5, "min_similarity": 0.45}
        return await store.search(TenantId(tenant), vector, **(options | kwargs))
    finally:
        await store.aclose()


async def test_finds_the_closest_chunks_with_their_source(
    knowledge_db: tuple[AsyncEngine, str],
) -> None:
    admin, reader = knowledge_db
    await add_document(
        admin,
        1,
        "Política de devoluciones",
        [
            ("Tienes 30 días para devolver.", unit(1), {"heading": "Plazo para devolver"}),
            ("Envíos a Canarias: 14,95 €.", unit(2), {"page": 3}),
            ("Algo parecido.", unit(1, 5), {}),
        ],
    )

    results = await search(reader, 1, unit(1))

    assert [r.content for r in results] == ["Tienes 30 días para devolver.", "Algo parecido."]
    assert results[0].similarity == 1.0
    assert results[0].source == "Política de devoluciones, sección «Plazo para devolver»"
    assert 0.45 < results[1].similarity < 1


async def test_never_returns_another_tenants_documents(
    knowledge_db: tuple[AsyncEngine, str],
) -> None:
    admin, reader = knowledge_db
    await add_document(admin, 1, "Acme", [("Datos de Acme", unit(1), {})])
    await add_document(admin, 2, "Globex", [("Datos de Globex", unit(1), {})])  # mismo vector

    acme = await search(reader, 1, unit(1))
    globex = await search(reader, 2, unit(1))
    nobody = await search(reader, 3, unit(1))

    assert [r.title for r in acme] == ["Acme"]
    assert [r.title for r in globex] == ["Globex"]
    assert nobody == []


async def test_row_level_security_is_a_second_barrier(
    knowledge_db: tuple[AsyncEngine, str],
) -> None:
    # Aunque una consulta olvidara el WHERE tenant_id, RLS solo deja ver la empresa
    # de la sesión; sin empresa en la sesión, nada.
    admin, reader = knowledge_db
    await add_document(admin, 1, "Acme", [("Datos de Acme", unit(1), {})])
    await add_document(admin, 2, "Globex", [("Datos de Globex", unit(1), {})])
    engine = create_async_engine(reader)
    try:
        async with engine.begin() as conn:
            assert (await conn.execute(text("SELECT count(*) FROM document_chunks"))).scalar() == 0
            await conn.execute(text("SELECT set_config('app.tenant_id', '2', true)"))
            titles = (await conn.execute(text("SELECT title FROM documents"))).scalars().all()
        assert titles == ["Globex"]
    finally:
        await engine.dispose()


@pytest.mark.parametrize(
    "sql",
    [
        "SELECT path FROM documents",
        "UPDATE document_chunks SET content = 'x'",
        "DELETE FROM documents",
        "INSERT INTO documents (tenant_id, title) VALUES (1, 'x')",
    ],
)
async def test_the_reader_cannot_write_or_read_other_columns(
    knowledge_db: tuple[AsyncEngine, str], sql: str
) -> None:
    _, reader = knowledge_db
    with pytest.raises(ProgrammingError, match="permission denied"):
        await run_as_reader(reader, sql)


async def test_only_ready_documents_of_the_same_embedding_model(
    knowledge_db: tuple[AsyncEngine, str],
) -> None:
    admin, reader = knowledge_db
    await add_document(admin, 1, "Listo", [("ok", unit(1), {})])
    await add_document(admin, 1, "Indexando", [("no", unit(1), {})], status="processing")
    await add_document(admin, 1, "Otro modelo", [("no", unit(1), {})], model="nomic-embed")

    assert [r.title for r in await search(reader, 1, unit(1))] == ["Listo"]


async def test_respects_the_similarity_threshold_and_the_limit(
    knowledge_db: tuple[AsyncEngine, str],
) -> None:
    admin, reader = knowledge_db
    await add_document(
        admin,
        1,
        "Doc",
        [(f"trozo {i}", unit(1, 10 + i), {}) for i in range(6)] + [("lejos", unit(9), {})],
    )

    assert len(await search(reader, 1, unit(1), limit=3)) == 3
    assert [r.content for r in await search(reader, 1, unit(9))] == ["lejos"]
    assert await search(reader, 1, unit(9), min_similarity=1.01) == []


async def test_database_errors_become_knowledge_base_errors() -> None:
    store = PgVectorStore.create("postgresql+psycopg://nadie:mal@127.0.0.1:1/nada")
    try:
        with pytest.raises(KnowledgeBaseError, match="No se pudo consultar la documentación"):
            await store.search(
                TenantId(1), unit(1), embedding_model="bge-m3", limit=5, min_similarity=0.4
            )
    finally:
        await store.aclose()
