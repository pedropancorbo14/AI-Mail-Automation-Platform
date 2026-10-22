"""Base de conocimiento local, en memoria, para la evaluación (Fase 15).

En producción los fragmentos se buscan con bge-m3 (Ollama) en pgvector. Para
evaluar sin esos servicios, aquí se usa una **bolsa de palabras con hashing**
(sin acentos ni palabras vacías, normalizada): encuentra los fragmentos que
comparten palabras con la pregunta. Es una aproximación —no entiende
sinónimos ni otros idiomas—, suficiente para que el agente tenga la
documentación de ejemplo delante; la calidad del RAG real se mide aparte.
"""

import hashlib
import math
import re
import time
import unicodedata
from collections.abc import Sequence
from dataclasses import dataclass, field

from ai_service.application.dto import EmbeddingBatch, EmbeddingResult, LLMCall
from ai_service.domain.email import TenantId
from ai_service.domain.knowledge import KnowledgeChunk, TextChunk

MODEL = "local-bow"
DIMENSIONS = 512
_WORD = re.compile(r"[a-z0-9]+")
_STOPWORDS = frozenset(
    """de la el en y a los las del que se un una por con para es no al lo su sus mi me
    te tu os o como mas pero si ya hay este esta ese esa son ser hola gracias the
    and to of in is for on my do you it be are with""".split()  # noqa: SIM905 - más legible que una lista
)


def _words(text: str) -> list[str]:
    plain = unicodedata.normalize("NFKD", text.lower()).encode("ascii", "ignore").decode()
    return [w for w in _WORD.findall(plain) if len(w) > 2 and w not in _STOPWORDS]


def embed(text: str) -> tuple[float, ...]:
    vector = [0.0] * DIMENSIONS
    for word in _words(text):
        index = int.from_bytes(hashlib.blake2b(word.encode(), digest_size=4).digest(), "big")
        vector[index % DIMENSIONS] += 1.0
    norm = math.sqrt(sum(v * v for v in vector)) or 1.0
    return tuple(v / norm for v in vector)


def _call(purpose: str, text: str, started: float) -> LLMCall:
    return LLMCall(
        purpose=purpose,
        provider="local",
        requested_model=MODEL,
        model=MODEL,
        status="success",
        input_tokens=len(_words(text)),
        latency_ms=int((time.perf_counter() - started) * 1000),
    )


class LocalEmbeddings:
    """`EmbeddingPort` sin red ni coste."""

    model = MODEL

    async def embed_query(self, text: str) -> EmbeddingResult:
        started = time.perf_counter()
        return EmbeddingResult(embed(text), _call("ai_service.knowledge_search", text, started))

    async def embed_documents(self, texts: list[str], batch_size: int = 16) -> EmbeddingBatch:
        started = time.perf_counter()
        call = _call("ai_service.document_indexing", " ".join(texts), started)
        return EmbeddingBatch(tuple(embed(t) for t in texts), (call,))


@dataclass
class MemoryVectorStore:
    """`VectorStorePort` en memoria, siempre por empresa."""

    _chunks: dict[int, list[tuple[KnowledgeChunk, tuple[float, ...]]]] = field(default_factory=dict)

    def add(
        self,
        tenant_id: TenantId,
        document_id: int,
        title: str,
        chunks: Sequence[TextChunk],
        vectors: Sequence[Sequence[float]],
    ) -> None:
        rows = self._chunks.setdefault(tenant_id.value, [])
        for chunk, vector in zip(chunks, vectors, strict=True):
            fragment = KnowledgeChunk(
                chunk_id=len(rows) + 1,
                document_id=document_id,
                title=title,
                content=chunk.text,
                similarity=0.0,
                metadata=dict(chunk.metadata),
            )
            rows.append((fragment, tuple(vector)))

    async def search(
        self,
        tenant_id: TenantId,
        vector: Sequence[float],
        *,
        embedding_model: str,
        limit: int,
        min_similarity: float,
    ) -> list[KnowledgeChunk]:
        scored = []
        for chunk, stored in self._chunks.get(tenant_id.value, []):
            similarity = sum(a * b for a, b in zip(vector, stored, strict=True))
            if similarity >= min_similarity:
                scored.append((similarity, chunk))
        scored.sort(key=lambda pair: pair[0], reverse=True)
        return [
            KnowledgeChunk(
                chunk_id=c.chunk_id,
                document_id=c.document_id,
                title=c.title,
                content=c.content,
                similarity=round(s, 4),
                metadata=c.metadata,
            )
            for s, c in scored[:limit]
        ]
