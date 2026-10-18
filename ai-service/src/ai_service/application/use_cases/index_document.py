"""Indexar y borrar documentos de la base de conocimiento (el indexado es de
ai-service: Laravel guarda el fichero y el estado, ai-service los fragmentos).

`IndexDocument`: descarga el original (URL firmada de Laravel) → extrae el
texto por secciones → trocea → calcula los embeddings (con el contexto
"Documento / Sección" delante, como hacía Laravel; se guarda solo el
fragmento) → sustituye los fragmentos del documento → `document.indexed.v1`.

- Error del documento (formato, vacío, PDF escaneado, dañado, enlace
  caducado) o de configuración de embeddings → `document.indexing_failed.v1`
  con un motivo para el usuario; reintentar no cambiaría nada.
- Error temporal (descarga, Ollama o PostgreSQL caídos) → se relanza: el
  consumidor deja el mensaje sin confirmar y se reintenta.
"""

import time
from dataclasses import dataclass
from typing import Literal

from ai_service.application.contracts.events import (
    DocumentDeleted,
    DocumentErrorCode,
    DocumentIndexed,
    DocumentIndexingFailed,
    DocumentUploaded,
)
from ai_service.application.dto import LLMCall
from ai_service.application.errors import (
    DocumentDownloadError,
    DocumentProcessingError,
    KnowledgeBaseError,
    LLMError,
)
from ai_service.application.mappers import usage_from_calls
from ai_service.application.ports import (
    DocumentSourcePort,
    EmbeddingPort,
    EventBusPort,
    KnowledgeIndexPort,
    TextExtractorPort,
)
from ai_service.application.services.chunking import TextChunker
from ai_service.domain.email import TenantId
from ai_service.domain.knowledge import TextChunk

DocumentOutcome = Literal["indexed", "failed", "deleted"]


class DocumentTemporaryError(Exception):
    """Fallo temporal al indexar: el consumidor reintentará. `code` es el que se
    publica si se agotan los reintentos."""

    def __init__(self, message: str, code: DocumentErrorCode) -> None:
        super().__init__(message)
        self.code: DocumentErrorCode = code


@dataclass(frozen=True, slots=True)
class DocumentResult:
    outcome: DocumentOutcome
    event: DocumentIndexed | DocumentIndexingFailed | None = None


class IndexDocument:
    def __init__(
        self,
        source: DocumentSourcePort,
        extractor: TextExtractorPort,
        chunker: TextChunker,
        embeddings: EmbeddingPort,
        index: KnowledgeIndexPort,
        bus: EventBusPort,
        *,
        batch_size: int = 16,
    ) -> None:
        self._source = source
        self._extractor = extractor
        self._chunker = chunker
        self._embeddings = embeddings
        self._index = index
        self._bus = bus
        self._batch = batch_size

    async def execute(self, event: DocumentUploaded) -> DocumentResult:
        started = time.perf_counter()
        calls: tuple[LLMCall, ...] = ()
        try:
            content = await self._source.fetch(event.download_path)
            chunks = self._chunker.chunk(self._extractor.extract(content, event.mime_type))
            if not chunks:
                raise DocumentProcessingError("empty", "El documento está vacío.")
            batch = await self._embeddings.embed_documents(
                [embedding_text(event.title, chunk) for chunk in chunks], self._batch
            )
            calls = batch.calls
            await self._index.replace_document(
                TenantId(event.tenant_id),
                event.document_id,
                event.title,
                chunks,
                batch.vectors,
                self._embeddings.model,
            )
        except DocumentProcessingError as error:
            return await self._failed(event, _code(error.code), error.message, ())
        except LLMError as error:
            if error.retryable:
                raise DocumentTemporaryError(error.message, "embeddings") from error
            return await self._failed(event, "embeddings", error.message, error.calls)
        except DocumentDownloadError as error:
            raise DocumentTemporaryError(str(error), "download") from error
        except KnowledgeBaseError as error:
            raise DocumentTemporaryError(str(error), "internal") from error

        indexed = DocumentIndexed(
            trace_id=event.trace_id,
            tenant_id=event.tenant_id,
            document_id=event.document_id,
            causation_id=event.event_id,
            chunks_count=len(chunks),
            embedding_model=self._embeddings.model,
            usage=usage_from_calls(calls),
            duration_ms=int((time.perf_counter() - started) * 1000),
        )
        await self._bus.publish(indexed)
        return DocumentResult("indexed", indexed)

    async def _failed(
        self,
        event: DocumentUploaded,
        code: DocumentErrorCode,
        message: str,
        calls: tuple[LLMCall, ...],
    ) -> DocumentResult:
        failed = DocumentIndexingFailed(
            trace_id=event.trace_id,
            tenant_id=event.tenant_id,
            document_id=event.document_id,
            causation_id=event.event_id,
            error_code=code,
            message=message[:500] or "No se pudo indexar el documento.",
            retryable=False,
            usage=usage_from_calls(calls),
        )
        await self._bus.publish(failed)
        return DocumentResult("failed", failed)

    async def give_up(
        self, event: DocumentUploaded, message: str, code: DocumentErrorCode = "internal"
    ) -> DocumentResult:
        """Tras agotar los reintentos de un fallo temporal: se le dice a Laravel."""
        return await self._failed(event, code, message, ())


class DeleteDocument:
    def __init__(self, index: KnowledgeIndexPort) -> None:
        self._index = index

    async def execute(self, event: DocumentDeleted) -> DocumentResult:
        try:
            await self._index.delete_document(TenantId(event.tenant_id), event.document_id)
        except KnowledgeBaseError as error:
            raise DocumentTemporaryError(str(error), "internal") from error
        return DocumentResult("deleted")


def embedding_text(title: str, chunk: TextChunk) -> str:
    """Lo que se convierte en vector: el fragmento con su contexto (como Laravel)."""
    header = f"Documento: {title}"
    heading = chunk.metadata.get("heading")
    if heading:
        header += f"\nSección: {heading}"
    return f"{header}\n\n{chunk.text}"


_CODES: dict[str, DocumentErrorCode] = {
    c: c for c in ("unsupported", "empty", "no_text", "unreadable", "too_large", "download")
}


def _code(code: str) -> DocumentErrorCode:
    return _CODES.get(code, "internal")
