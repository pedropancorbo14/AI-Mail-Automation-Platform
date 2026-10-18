"""Base de conocimiento de ai-service en pgvector real: su propio esquema y
rol, todo por empresa."""

import math
from collections.abc import AsyncIterator

import pytest
from sqlalchemy import text
from sqlalchemy.ext.asyncio import AsyncEngine

from ai_service.application.errors import KnowledgeBaseError
from ai_service.domain.email import TenantId
from ai_service.domain.knowledge import TextChunk
from ai_service.infrastructure.knowledge.pgvector import PgVectorStore

DIMS = 1024
ACME, GLOBEX = TenantId(1), TenantId(2)


def unit(*hot: int) -> list[float]:
    """Vector normalizado con valor en las posiciones indicadas (parecido controlable)."""
    v = [0.0] * DIMS
    for i in hot:
        v[i] = 1.0
    norm = math.sqrt(sum(x * x for x in v))
    return [x / norm for x in v]


@pytest.fixture
async def store(knowledge_db: tuple[AsyncEngine, str]) -> AsyncIterator[PgVectorStore]:
    _, url = knowledge_db
    store = PgVectorStore.create(url, DIMS)
    await store.setup()
    yield store
    await store.aclose()


async def index(
    store: PgVectorStore,
    tenant: TenantId,
    document: int,
    *items: tuple[str, list[float]],
    title: str = "Envíos",
    model: str = "bge-m3",
) -> None:
    chunks = [TextChunk(text, {"heading": text.split()[0]}) for text, _ in items]
    await store.replace_document(tenant, document, title, chunks, [v for _, v in items], model)


async def test_the_table_lives_in_the_ai_service_schema_and_setup_is_idempotent(
    store: PgVectorStore, knowledge_db: tuple[AsyncEngine, str]
) -> None:
    await store.setup()
    admin, _ = knowledge_db
    async with admin.connect() as conn:
        schemas = (
            (
                await conn.execute(
                    text(
                        "SELECT table_schema FROM information_schema.tables "
                        "WHERE table_name = 'knowledge_chunks'"
                    )
                )
            )
            .scalars()
            .all()
        )
    assert schemas == ["ai_service"]


async def test_search_only_returns_chunks_of_the_tenant(store: PgVectorStore) -> None:
    await index(store, ACME, 1, ("Canarias 14,95 €", unit(1)))
    await index(store, GLOBEX, 1, ("Secreto de Globex", unit(1)), title="Globex")

    found = await store.search(ACME, unit(1), embedding_model="bge-m3", limit=5, min_similarity=0.1)

    assert [c.content for c in found] == ["Canarias 14,95 €"]
    assert found[0].source == "Envíos, sección «Canarias»"
    assert found[0].similarity == 1.0


async def test_reindexing_replaces_the_chunks_of_the_document(store: PgVectorStore) -> None:
    await index(store, ACME, 7, ("Viejo", unit(1)), ("Otro viejo", unit(2)))
    await index(store, ACME, 7, ("Nuevo", unit(1)))

    found = await store.search(ACME, unit(1), embedding_model="bge-m3", limit=5, min_similarity=0.0)

    assert [c.content for c in found] == ["Nuevo"]


async def test_deleting_only_touches_that_tenant_and_document(store: PgVectorStore) -> None:
    await index(store, ACME, 7, ("De Acme", unit(1)))
    await index(store, GLOBEX, 7, ("De Globex", unit(1)))

    assert await store.delete_document(ACME, 7) == 1

    assert (
        await store.search(ACME, unit(1), embedding_model="bge-m3", limit=5, min_similarity=0.0)
        == []
    )
    still = await store.search(
        GLOBEX, unit(1), embedding_model="bge-m3", limit=5, min_similarity=0.0
    )
    assert [c.content for c in still] == ["De Globex"]


async def test_only_chunks_of_the_same_embedding_model_with_the_threshold_and_limit(
    store: PgVectorStore,
) -> None:
    await index(store, ACME, 1, ("Parecido", unit(1, 2)), ("Lejano", unit(9)), ("Igual", unit(1)))
    await index(store, ACME, 2, ("Otro modelo", unit(1)), model="otro-modelo")

    found = await store.search(ACME, unit(1), embedding_model="bge-m3", limit=1, min_similarity=0.5)

    assert [c.content for c in found] == ["Igual"]
    more = await store.search(ACME, unit(1), embedding_model="bge-m3", limit=5, min_similarity=0.5)
    assert [c.content for c in more] == ["Igual", "Parecido"]


async def test_database_errors_become_knowledge_base_errors() -> None:
    store = PgVectorStore.create("postgresql+psycopg://nadie:x@127.0.0.1:1/nada", DIMS)
    with pytest.raises(KnowledgeBaseError):
        await store.search(ACME, unit(1), embedding_model="bge-m3", limit=5, min_similarity=0.1)
    await store.aclose()
