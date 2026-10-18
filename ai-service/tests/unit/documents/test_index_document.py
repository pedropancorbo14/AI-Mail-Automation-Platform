"""Indexar y borrar documentos (caso de uso con dobles en memoria)."""

from collections.abc import Sequence
from dataclasses import dataclass, field

import pytest

from ai_service.application.contracts.events import (
    DocumentDeleted,
    DocumentIndexed,
    DocumentIndexingFailed,
    DocumentUploaded,
)
from ai_service.application.errors import (
    DocumentDownloadError,
    DocumentProcessingError,
    KnowledgeBaseError,
    LLMConfigurationError,
    LLMUnavailableError,
)
from ai_service.application.services.chunking import TextChunker
from ai_service.application.use_cases.index_document import (
    DeleteDocument,
    DocumentTemporaryError,
    IndexDocument,
)
from ai_service.domain.email import TenantId
from ai_service.domain.knowledge import TextChunk
from ai_service.infrastructure.documents.extraction import DocumentTextExtractor
from tests.fakes import FakeEmbeddings, InMemoryEventBus
from tests.support.contracts import assert_matches_contract, example

MARKDOWN = b"# Envios\n\n## Canarias\nCuesta 14,95 euros.\n\n## Baleares\nCuesta 9,95 euros."


@dataclass
class FakeSource:
    content: bytes = MARKDOWN
    error: Exception | None = None
    paths: list[str] = field(default_factory=list)

    async def fetch(self, path: str) -> bytes:
        self.paths.append(path)
        if self.error:
            raise self.error
        return self.content


@dataclass
class FakeIndex:
    documents: dict[tuple[int, int], list[tuple[str, str, Sequence[float]]]] = field(
        default_factory=dict
    )
    error: KnowledgeBaseError | None = None

    async def replace_document(
        self,
        tenant_id: TenantId,
        document_id: int,
        title: str,
        chunks: Sequence[TextChunk],
        vectors: Sequence[Sequence[float]],
        embedding_model: str,
    ) -> None:
        if self.error:
            raise self.error
        self.documents[(tenant_id.value, document_id)] = [
            (title, c.text, v) for c, v in zip(chunks, vectors, strict=True)
        ]

    async def delete_document(self, tenant_id: TenantId, document_id: int) -> int:
        if self.error:
            raise self.error
        return len(self.documents.pop((tenant_id.value, document_id), []))


def uploaded() -> DocumentUploaded:
    return DocumentUploaded.model_validate(example("document.uploaded.v1"))


def indexer(
    source: FakeSource | None = None,
    embeddings: FakeEmbeddings | None = None,
    index: FakeIndex | None = None,
    bus: InMemoryEventBus | None = None,
) -> IndexDocument:
    return IndexDocument(
        source or FakeSource(),
        DocumentTextExtractor(),
        TextChunker(),
        embeddings or FakeEmbeddings(),
        index or FakeIndex(),
        bus or InMemoryEventBus(),
    )


async def test_indexes_the_document_and_tells_laravel() -> None:
    source, embeddings, index, bus = FakeSource(), FakeEmbeddings(), FakeIndex(), InMemoryEventBus()
    event = uploaded()

    result = await indexer(source, embeddings, index, bus).execute(event)

    assert result.outcome == "indexed"
    assert source.paths == [event.download_path]
    stored = index.documents[(event.tenant_id, event.document_id)]
    assert [text for _, text, _ in stored] == [
        "# Envios\n\n## Canarias\nCuesta 14,95 euros.",
        "## Baleares\nCuesta 9,95 euros.",
    ]
    # El vector se calcula con el contexto delante; se guarda solo el fragmento.
    assert embeddings.documents[0][0].startswith("Documento: Envíos y entregas\nSección: Canarias")
    [indexed] = bus.published
    assert isinstance(indexed, DocumentIndexed)
    assert (indexed.chunks_count, indexed.embedding_model) == (2, "bge-m3")
    assert indexed.causation_id == event.event_id
    assert [c.purpose for c in indexed.usage.calls] == ["ai_service.document_indexing"]
    assert_matches_contract("document.indexed.v1", indexed.to_json_dict())


@pytest.mark.parametrize(
    ("source", "code"),
    [
        (FakeSource(content=b"  "), "empty"),
        (FakeSource(error=DocumentProcessingError("download", "El enlace caducó.")), "download"),
    ],
)
async def test_documents_that_cannot_be_indexed_are_reported(source: FakeSource, code: str) -> None:
    bus = InMemoryEventBus()

    result = await indexer(source=source, bus=bus).execute(uploaded())

    assert result.outcome == "failed"
    [failed] = bus.published
    assert isinstance(failed, DocumentIndexingFailed)
    assert (failed.error_code, failed.retryable) == (code, False)
    assert_matches_contract("document.indexing_failed.v1", failed.to_json_dict())


async def test_bad_embeddings_configuration_is_reported_with_its_calls() -> None:
    bus = InMemoryEventBus()
    embeddings = FakeEmbeddings(error=LLMConfigurationError("Ollama no tiene el modelo bge-m3"))

    await indexer(embeddings=embeddings, bus=bus).execute(uploaded())

    [failed] = bus.published
    assert isinstance(failed, DocumentIndexingFailed)
    assert failed.error_code == "embeddings"
    assert "bge-m3" in failed.message


@pytest.mark.parametrize(
    "kwargs",
    [
        {"source": FakeSource(error=DocumentDownloadError("Laravel respondió 502."))},
        {"embeddings": FakeEmbeddings(error=LLMUnavailableError("Ollama caído"))},
        {"index": FakeIndex(error=KnowledgeBaseError("BD caída"))},
    ],
)
async def test_temporary_failures_are_retried(kwargs: dict[str, object]) -> None:
    bus = InMemoryEventBus()
    with pytest.raises(DocumentTemporaryError):
        await indexer(bus=bus, **kwargs).execute(uploaded())  # type: ignore[arg-type]
    assert bus.published == []


async def test_giving_up_after_retries_tells_laravel() -> None:
    bus = InMemoryEventBus()

    await indexer(bus=bus).give_up(uploaded(), "No se pudo descargar el documento.", "download")

    [failed] = bus.published
    assert isinstance(failed, DocumentIndexingFailed)
    assert failed.error_code == "download"


async def test_deleting_removes_the_chunks_of_that_tenant() -> None:
    index = FakeIndex()
    await indexer(index=index).execute(uploaded())
    deleted = DocumentDeleted.model_validate(example("document.deleted.v1"))

    result = await DeleteDocument(index).execute(deleted)

    assert result.outcome == "deleted"
    assert index.documents == {}
