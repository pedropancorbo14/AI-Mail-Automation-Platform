"""Recuperación de documentación para un email (RAG)."""

from dataclasses import dataclass

from ai_service.application.dto import RetrievalResult
from ai_service.application.errors import KnowledgeBaseError, LLMError
from ai_service.application.ports import EmbeddingPort, VectorStorePort
from ai_service.domain.email import Email


@dataclass(frozen=True, slots=True)
class RetrievalOptions:
    limit: int = 5
    # bge-m3: relacionado ≈ 0,5; sin relación ≈ 0,35 (mismo umbral que Laravel).
    min_similarity: float = 0.45
    max_query_chars: int = 2000


class KnowledgeRetriever:
    """Pregunta = asunto + cuerpo (como en Laravel) → vector → pgvector.

    Si el modelo de embeddings o la base de datos fallan, no se lanza: se
    devuelve `failed` con el motivo y el email sigue (irá a revisión humana).
    Un email no debe quedarse atascado porque Ollama esté parado.
    """

    def __init__(
        self,
        embeddings: EmbeddingPort,
        store: VectorStorePort,
        options: RetrievalOptions | None = None,
    ) -> None:
        self._embeddings = embeddings
        self._store = store
        self._options = options or RetrievalOptions()

    @staticmethod
    def query_for(email: Email, max_chars: int = 2000) -> str:
        return f"{email.subject}\n\n{email.body}".strip()[:max_chars]

    async def retrieve(self, email: Email) -> RetrievalResult:
        query = self.query_for(email, self._options.max_query_chars)
        if not query:
            return RetrievalResult(chunks=())
        try:
            embedding = await self._embeddings.embed_query(query)
        except LLMError as error:
            return RetrievalResult(chunks=(), calls=error.calls, failed=error.message)
        try:
            chunks = await self._store.search(
                email.tenant_id,
                embedding.vector,
                embedding_model=self._embeddings.model,
                limit=self._options.limit,
                min_similarity=self._options.min_similarity,
            )
        except KnowledgeBaseError as error:
            return RetrievalResult(chunks=(), calls=(embedding.call,), failed=str(error))
        return RetrievalResult(chunks=tuple(chunks), calls=(embedding.call,))
