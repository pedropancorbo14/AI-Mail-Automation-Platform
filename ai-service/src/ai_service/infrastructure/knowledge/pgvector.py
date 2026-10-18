"""Base de conocimiento de ai-service en PostgreSQL + pgvector.

Los fragmentos son de ai-service: tabla `ai_service.knowledge_chunks` en su
propio esquema, con su propio rol (el mismo que los checkpoints: solo tiene
permisos en ese esquema, nunca en tablas de Laravel). Laravel guarda el
documento (fichero, estado, permisos) y ai-service lo indexa y lo busca.

- Toda consulta lleva `tenant_id`: no existe una búsqueda ni un borrado "de
  todas las empresas".
- `SET LOCAL hnsw.iterative_scan = relaxed_order`: el índice HNSW filtra la
  empresa después de buscar vecinos; así completa el límite dentro de ella.
- Solo fragmentos indexados con el mismo modelo de embeddings que la pregunta.
- Reindexar sustituye los fragmentos del documento en una transacción: nunca
  queda un documento a medias.
"""

import json
from collections.abc import Sequence
from typing import Any

from sqlalchemy import text
from sqlalchemy.exc import SQLAlchemyError
from sqlalchemy.ext.asyncio import AsyncEngine, create_async_engine

from ai_service.application.errors import KnowledgeBaseError
from ai_service.domain.email import TenantId
from ai_service.domain.knowledge import KnowledgeChunk, TextChunk

TABLE = "ai_service.knowledge_chunks"

_SEARCH = text(
    f"""
    SELECT id, document_id, document_title, content, metadata,
           1 - (embedding <=> CAST(:vector AS vector)) AS similarity
    FROM {TABLE}
    WHERE tenant_id = :tenant AND embedding_model = :model
      AND (embedding <=> CAST(:vector AS vector)) < :max_distance
    ORDER BY embedding <=> CAST(:vector AS vector)
    LIMIT :limit
    """  # noqa: S608 (TABLE es una constante del código)
)
_DELETE = text(f"DELETE FROM {TABLE} WHERE tenant_id = :tenant AND document_id = :document")  # noqa: S608
_INSERT = text(
    f"""
    INSERT INTO {TABLE}
        (tenant_id, document_id, document_title, position, content, metadata,
         embedding, embedding_model)
    VALUES (:tenant, :document, :title, :position, :content, CAST(:metadata AS jsonb),
            CAST(:vector AS vector), :model)
    """  # noqa: S608
)


class PgVectorStore:
    """Implementa `VectorStorePort` y `KnowledgeIndexPort`."""

    def __init__(self, engine: AsyncEngine, dimensions: int = 1024) -> None:
        self._engine = engine
        self._dimensions = dimensions

    @classmethod
    def create(cls, url: str, dimensions: int = 1024) -> "PgVectorStore":
        engine = create_async_engine(url, pool_pre_ping=True, pool_size=5, max_overflow=5)
        return cls(engine, dimensions)

    async def setup(self) -> None:
        """Crea la tabla y sus índices si no existen (idempotente, al arrancar)."""
        statements = [
            f"""
            CREATE TABLE IF NOT EXISTS {TABLE} (
                id bigserial PRIMARY KEY,
                tenant_id bigint NOT NULL,
                document_id bigint NOT NULL,
                document_title text NOT NULL,
                position integer NOT NULL,
                content text NOT NULL,
                metadata jsonb NOT NULL DEFAULT '{{}}'::jsonb,
                embedding vector({int(self._dimensions)}) NOT NULL,
                embedding_model text NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE (tenant_id, document_id, position)
            )
            """,
            f"CREATE INDEX IF NOT EXISTS knowledge_chunks_tenant_document "
            f"ON {TABLE} (tenant_id, document_id)",
            f"CREATE INDEX IF NOT EXISTS knowledge_chunks_embedding "
            f"ON {TABLE} USING hnsw (embedding vector_cosine_ops)",
        ]
        try:
            async with self._engine.begin() as conn:
                for statement in statements:
                    await conn.execute(text(statement))
        except SQLAlchemyError as error:
            raise KnowledgeBaseError(
                f"No se pudo preparar la base de conocimiento ({type(error).__name__})."
            ) from error

    async def search(
        self,
        tenant_id: TenantId,
        vector: Sequence[float],
        *,
        embedding_model: str,
        limit: int,
        min_similarity: float,
    ) -> list[KnowledgeChunk]:
        try:
            async with self._engine.begin() as conn:
                await conn.execute(
                    text("SELECT set_config('hnsw.iterative_scan', 'relaxed_order', true)")
                )
                rows = (
                    await conn.execute(
                        _SEARCH,
                        {
                            "vector": _literal(vector),
                            "tenant": tenant_id.value,
                            "model": embedding_model,
                            "max_distance": 1 - min_similarity,
                            "limit": limit,
                        },
                    )
                ).mappings()
                chunks = [_chunk(row) for row in rows]
        except SQLAlchemyError as error:
            raise KnowledgeBaseError(
                f"No se pudo consultar la documentación ({type(error).__name__})."
            ) from error
        # relaxed_order puede alterar ligeramente el orden: se reordena aquí.
        return sorted(chunks, key=lambda c: c.similarity, reverse=True)

    async def replace_document(
        self,
        tenant_id: TenantId,
        document_id: int,
        title: str,
        chunks: Sequence[TextChunk],
        vectors: Sequence[Sequence[float]],
        embedding_model: str,
    ) -> None:
        if len(chunks) != len(vectors):
            raise ValueError("Un vector por fragmento.")
        params = {"tenant": tenant_id.value, "document": document_id}
        rows = [
            {
                **params,
                "title": title,
                "position": position,
                "content": chunk.text,
                "metadata": json.dumps(dict(chunk.metadata)),
                "vector": _literal(vector),
                "model": embedding_model,
            }
            for position, (chunk, vector) in enumerate(zip(chunks, vectors, strict=True))
        ]
        try:
            async with self._engine.begin() as conn:
                await conn.execute(_DELETE, params)
                if rows:
                    await conn.execute(_INSERT, rows)
        except SQLAlchemyError as error:
            raise KnowledgeBaseError(
                f"No se pudieron guardar los fragmentos ({type(error).__name__})."
            ) from error

    async def delete_document(self, tenant_id: TenantId, document_id: int) -> int:
        try:
            async with self._engine.begin() as conn:
                result = await conn.execute(
                    _DELETE, {"tenant": tenant_id.value, "document": document_id}
                )
        except SQLAlchemyError as error:
            raise KnowledgeBaseError(
                f"No se pudieron borrar los fragmentos ({type(error).__name__})."
            ) from error
        return int(getattr(result, "rowcount", 0) or 0)

    async def aclose(self) -> None:
        await self._engine.dispose()


def _literal(vector: Sequence[float]) -> str:
    return "[" + ",".join(repr(float(v)) for v in vector) + "]"


def _chunk(row: Any) -> KnowledgeChunk:
    metadata = row["metadata"] if isinstance(row["metadata"], dict) else {}
    return KnowledgeChunk(
        chunk_id=int(row["id"]),
        document_id=int(row["document_id"]),
        title=str(row["document_title"]),
        content=str(row["content"]),
        similarity=round(float(row["similarity"]), 4),
        metadata=metadata,
    )
