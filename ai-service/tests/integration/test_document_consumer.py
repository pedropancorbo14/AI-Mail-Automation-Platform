"""Consumidor de documentos con Redis y PostgreSQL reales: Laravel publica
`document.uploaded.v1`, ai-service indexa en su esquema y responde."""

import json
from typing import Any

from redis.asyncio import Redis
from sqlalchemy.ext.asyncio import AsyncEngine

from ai_service.application.services.chunking import TextChunker
from ai_service.application.use_cases.index_document import DeleteDocument, IndexDocument
from ai_service.domain.email import TenantId
from ai_service.entrypoints.consumer import ConsumerOptions
from ai_service.entrypoints.document_consumer import DELETED, UPLOADED, DocumentConsumer
from ai_service.infrastructure.documents.extraction import DocumentTextExtractor
from ai_service.infrastructure.events.redis_streams import (
    RedisStreamConsumer,
    RedisStreamsEventBus,
)
from ai_service.infrastructure.knowledge.pgvector import PgVectorStore
from ai_service.infrastructure.observability.metrics import ServiceMetrics
from tests.fakes import FakeEmbeddings
from tests.support.contracts import assert_matches_contract, example
from tests.unit.documents.test_index_document import FakeSource

OPTIONS = ConsumerOptions(read_block_ms=100, claim_idle_ms=0, max_deliveries=2)


async def publish(redis: Redis, stream: str, payload: dict[str, Any]) -> None:
    await redis.xadd(stream, {"event_id": payload["event_id"], "payload": json.dumps(payload)})


async def results(redis: Redis, stream: str) -> list[dict[str, Any]]:
    rows: Any = await redis.xrange(stream)
    return [json.loads(fields[b"payload"]) for _, fields in rows]


def consumer(
    redis: Redis, stream: str, handler: IndexDocument | DeleteDocument
) -> DocumentConsumer:
    return DocumentConsumer(
        RedisStreamConsumer(redis, stream=stream, group="ai-service", consumer="test-1"),
        handler,
        ServiceMetrics("testing"),
        OPTIONS,
    )


class Vectors(FakeEmbeddings):
    """1024 dimensiones como la tabla real."""

    async def embed_documents(self, texts: list[str], batch_size: int = 16):  # type: ignore[no-untyped-def]
        batch = await super().embed_documents(texts, batch_size)
        vectors = tuple(tuple([1.0] + [0.0] * 1023) for _ in texts)
        return type(batch)(vectors, batch.calls)


async def test_indexes_once_and_deletes(
    redis: Redis, knowledge_db: tuple[AsyncEngine, str]
) -> None:
    _, url = knowledge_db
    store = PgVectorStore.create(url)
    await store.setup()
    source = FakeSource()
    bus = RedisStreamsEventBus(redis)
    indexer = IndexDocument(source, DocumentTextExtractor(), TextChunker(), Vectors(), store, bus)
    uploads = consumer(redis, UPLOADED, indexer)
    await uploads._stream.ensure_group()
    event = example("document.uploaded.v1")

    await publish(redis, UPLOADED, event)
    await publish(redis, UPLOADED, event)  # Redis entrega al menos una vez
    for message in await uploads.poll():
        assert await uploads.handle(message) == "indexed"
    for message in await uploads.poll():
        assert await uploads.handle(message) == "duplicate"

    [indexed] = await results(redis, "document.indexed.v1")
    assert_matches_contract("document.indexed.v1", indexed)
    assert indexed["chunks_count"] == 2
    found = await store.search(
        TenantId(1), [1.0] + [0.0] * 1023, embedding_model="bge-m3", limit=5, min_similarity=0.5
    )
    assert {c.document_id for c in found} == {7}
    assert len(source.paths) == 1

    deletes = consumer(redis, DELETED, DeleteDocument(store))
    await deletes._stream.ensure_group()
    await publish(redis, DELETED, example("document.deleted.v1"))
    for message in await deletes.poll():
        assert await deletes.handle(message) == "deleted"
    assert (
        await store.search(
            TenantId(1), [1.0] + [0.0] * 1023, embedding_model="bge-m3", limit=5, min_similarity=0.0
        )
        == []
    )
    await store.aclose()


async def test_invalid_events_go_to_the_dead_letter_queue(redis: Redis) -> None:
    uploads = consumer(redis, UPLOADED, DeleteDocument(None))  # type: ignore[arg-type]
    await uploads._stream.ensure_group()
    await redis.xadd(UPLOADED, {"event_id": "x", "payload": "{}"})

    [message] = await uploads.poll()
    assert await uploads.handle(message) == "invalid"
    assert await redis.xlen(f"{UPLOADED}.dlq") == 1


async def test_a_temporary_failure_is_retried_and_then_reported(
    redis: Redis, knowledge_db: tuple[AsyncEngine, str]
) -> None:
    from ai_service.application.errors import DocumentDownloadError

    _, url = knowledge_db
    store = PgVectorStore.create(url)
    bus = RedisStreamsEventBus(redis)
    source = FakeSource(error=DocumentDownloadError("Laravel respondió 502."))
    uploads = consumer(
        redis,
        UPLOADED,
        IndexDocument(source, DocumentTextExtractor(), TextChunker(), Vectors(), store, bus),
    )
    await uploads._stream.ensure_group()
    await publish(redis, UPLOADED, example("document.uploaded.v1"))

    [first] = await uploads.poll()
    assert await uploads.handle(first) == "retry"
    [second] = await uploads.poll()  # reclamado (claim_idle_ms 0): 2ª entrega = última
    assert await uploads.handle(second) == "failed"

    [failed] = await results(redis, "document.indexing_failed.v1")
    assert_matches_contract("document.indexing_failed.v1", failed)
    assert failed["error_code"] == "download"
    await store.aclose()
