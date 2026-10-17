"""Embeddings con Ollama (bge-m3), el mismo modelo local que usa Laravel.

Mismo trato que `App\\AI\\Embeddings\\OllamaEmbeddingProvider`: `POST /api/embed`
con `truncate: true`, vectores **normalizados** a longitud 1 y dimensiones
comprobadas. Los documentos no salen del servidor (decisión de la Fase 6).
"""

import math
import time

import httpx

from ai_service.application.dto import EmbeddingResult, LLMCall
from ai_service.application.errors import (
    LLMConfigurationError,
    LLMInvalidOutputError,
    LLMUnavailableError,
)

PURPOSE = "ai_service.knowledge_search"


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
        started = time.perf_counter()
        try:
            response = await self._client.post(
                "/api/embed", json={"model": self._model, "input": [text], "truncate": True}
            )
        except httpx.HTTPError as error:
            raise LLMUnavailableError(
                "No se pudo conectar con Ollama: ¿está levantado el servicio ollama?",
                (self._call(started, "failed", error=type(error).__name__),),
            ) from error

        if response.status_code == 404:
            raise LLMConfigurationError(
                f"Ollama no tiene el modelo {self._model}: "
                f"docker compose exec ollama ollama pull {self._model}",
                (self._call(started, "failed", error="404"),),
            )
        if response.status_code >= 500:
            raise LLMUnavailableError(
                f"Ollama no está disponible ({response.status_code}).",
                (self._call(started, "failed", error=str(response.status_code)),),
            )
        if response.status_code >= 400:
            raise LLMConfigurationError(
                f"Ollama rechazó la petición ({response.status_code}).",
                (self._call(started, "failed", error=str(response.status_code)),),
            )

        body = response.json()
        tokens = int(body.get("prompt_eval_count") or 0)
        embeddings = body.get("embeddings")
        if not isinstance(embeddings, list) or len(embeddings) != 1:
            raise LLMInvalidOutputError(
                "Ollama no devolvió un vector por texto.",
                (self._call(started, "failed", tokens, error="invalid"),),
            )
        vector = [float(v) for v in embeddings[0]]
        if len(vector) != self._dimensions:
            raise LLMInvalidOutputError(
                f"El modelo {self._model} devuelve {len(vector)} dimensiones y la base de "
                f"datos espera {self._dimensions}.",
                (self._call(started, "failed", tokens, error="dimensions"),),
            )
        return EmbeddingResult(_normalize(vector), self._call(started, "success", tokens))

    def _call(
        self, started: float, status: str, tokens: int = 0, *, error: str | None = None
    ) -> LLMCall:
        return LLMCall(
            purpose=PURPOSE,
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
