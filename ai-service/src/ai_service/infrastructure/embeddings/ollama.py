"""Embeddings con Ollama (bge-m3), el mismo modelo local que usa Laravel.

Mismo trato que `App\\AI\\Embeddings\\OllamaEmbeddingProvider`: `POST /api/embed`
con `truncate: true`, vectores **normalizados** a longitud 1 y dimensiones
comprobadas. Los documentos no salen del servidor (decisión de la Fase 6).
"""

import math
import time

import httpx

from ai_service.application.dto import EmbeddingBatch, EmbeddingResult, LLMCall
from ai_service.application.errors import (
    LLMConfigurationError,
    LLMError,
    LLMInvalidOutputError,
    LLMUnavailableError,
)

PURPOSE = "ai_service.knowledge_search"
INDEXING_PURPOSE = "ai_service.document_indexing"


class OllamaEmbeddings:
    """Implementa `EmbeddingPort`."""

    def __init__(
        self,
        client: httpx.AsyncClient,
        *,
        model: str = "bge-m3",
        dimensions: int = 1024,
    ) -> None:
        self._client = client
        self._model = model
        self._dimensions = dimensions

    @classmethod
    def create(
        cls, base_url: str, *, model: str, dimensions: int, timeout: float
    ) -> "OllamaEmbeddings":
        return cls(
            httpx.AsyncClient(base_url=base_url, timeout=timeout),
            model=model,
            dimensions=dimensions,
        )

    @property
    def model(self) -> str:
        return self._model

    async def embed_query(self, text: str) -> EmbeddingResult:
        vectors, call = await self._embed([text], PURPOSE)
        return EmbeddingResult(vectors[0], call)

    async def embed_documents(self, texts: list[str], batch_size: int = 16) -> EmbeddingBatch:
        """Vectores de los fragmentos al indexar, en lotes y en el mismo orden.
        Si un lote falla, el error lleva todas las llamadas hechas (se registran)."""
        vectors: list[tuple[float, ...]] = []
        calls: list[LLMCall] = []
        for start in range(0, len(texts), batch_size):
            try:
                batch, call = await self._embed(texts[start : start + batch_size], INDEXING_PURPOSE)
            except LLMError as error:
                error.calls = (*calls, *error.calls)
                raise
            vectors.extend(batch)
            calls.append(call)
        return EmbeddingBatch(tuple(vectors), tuple(calls))

    async def _embed(
        self, texts: list[str], purpose: str
    ) -> tuple[list[tuple[float, ...]], LLMCall]:
        started = time.perf_counter()
        try:
            response = await self._client.post(
                "/api/embed", json={"model": self._model, "input": texts, "truncate": True}
            )
        except httpx.HTTPError as error:
            raise LLMUnavailableError(
                "No se pudo conectar con Ollama: ¿está levantado el servicio ollama?",
                (self._call(started, "failed", error=type(error).__name__, purpose=purpose),),
            ) from error

        if response.status_code == 404:
            raise LLMConfigurationError(
                f"Ollama no tiene el modelo {self._model}: "
                f"docker compose exec ollama ollama pull {self._model}",
                (self._call(started, "failed", error="404", purpose=purpose),),
            )
        if response.status_code >= 500:
            raise LLMUnavailableError(
                f"Ollama no está disponible ({response.status_code}).",
                (self._call(started, "failed", error=str(response.status_code), purpose=purpose),),
            )
        if response.status_code >= 400:
            raise LLMConfigurationError(
                f"Ollama rechazó la petición ({response.status_code}).",
                (self._call(started, "failed", error=str(response.status_code), purpose=purpose),),
            )

        body = response.json()
        tokens = int(body.get("prompt_eval_count") or 0)
        embeddings = body.get("embeddings")
        if not isinstance(embeddings, list) or len(embeddings) != len(texts):
            raise LLMInvalidOutputError(
                "Ollama no devolvió un vector por texto.",
                (self._call(started, "failed", tokens, error="invalid", purpose=purpose),),
            )
        vectors = []
        for raw in embeddings:
            vector = [float(v) for v in raw]
            if len(vector) != self._dimensions:
                raise LLMInvalidOutputError(
                    f"El modelo {self._model} devuelve {len(vector)} dimensiones y la base de "
                    f"datos espera {self._dimensions}.",
                    (self._call(started, "failed", tokens, error="dimensions", purpose=purpose),),
                )
            vectors.append(_normalize(vector))
        return vectors, self._call(started, "success", tokens, purpose=purpose)

    def _call(
        self,
        started: float,
        status: str,
        tokens: int = 0,
        *,
        error: str | None = None,
        purpose: str = PURPOSE,
    ) -> LLMCall:
        return LLMCall(
            purpose=purpose,
            provider="ollama",
            requested_model=self._model,
            model=self._model,
            status="success" if status == "success" else "failed",
            input_tokens=tokens,
            latency_ms=int((time.perf_counter() - started) * 1000),
            error=error,
        )

    async def aclose(self) -> None:
        await self._client.aclose()


def _normalize(vector: list[float]) -> tuple[float, ...]:
    norm = math.sqrt(sum(v * v for v in vector))
    return tuple(v / norm for v in vector) if norm > 0 else tuple(vector)
