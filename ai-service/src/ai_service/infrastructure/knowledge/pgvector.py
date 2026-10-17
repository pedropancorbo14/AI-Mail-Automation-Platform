"""Búsqueda en la documentación con PostgreSQL + pgvector, en SOLO LECTURA.

Se conecta con el rol `ai_service_reader` (contracts/database/): permisos de
solo lectura por columna y Row Level Security por empresa. Cada búsqueda va
en una transacción con:

- `SET LOCAL app.tenant_id`: RLS solo deja ver las filas de esa empresa
  (sin ella no se ve nada);
- además `WHERE tenant_id = :tenant` en la propia consulta (defensa en
  profundidad: dos barreras independientes);
- `SET LOCAL hnsw.iterative_scan = relaxed_order`: el índice HNSW filtra
  después de buscar vecinos; así completa el límite dentro de la empresa
  (igual que Laravel);
- solo documentos `ready` indexados con el mismo modelo de embeddings.
"""

from collections.abc import Sequence
from typing import Any

from sqlalchemy import text
from sqlalchemy.exc import SQLAlchemyError
from sqlalchemy.ext.asyncio import AsyncEngine, create_async_engine

from ai_service.application.errors import KnowledgeBaseError
from ai_service.domain.email import TenantId
from ai_service.domain.knowledge import KnowledgeChunk

_SEARCH = text(
    """
    SELECT c.id, c.document_id, d.title, c.content, c.metadata,
           1 - (c.embedding <=> CAST(:vector AS vector)) AS similarity
    FROM document_chunks c
    JOIN documents d ON d.id = c.document_id
    WHERE c.tenant_id = :tenant AND d.tenant_id = :tenant
      AND d.status = 'ready' AND d.embedding_model = :model
      AND (c.embedding <=> CAST(:vector AS vector)) < :max_distance
    ORDER BY c.embedding <=> CAST(:vector AS vector)
    LIMIT :limit
    """
)


class PgVectorStore:
    """Implementa `VectorStorePort`."""

    def __init__(self, engine: AsyncEngine) -> None:
        self._engine = engine

    @classmethod
    def create(cls, url: str) -> "PgVectorStore":
        return cls(create_async_engine(url, pool_pre_ping=True, pool_size=5, max_overflow=5))

    async def search(
        self,
        tenant_id: TenantId,
        vector: Sequence[float],
        *,
        embedding_model: str,
        limit: int,
        min_similarity: float,
    ) -> list[KnowledgeChunk]:
        literal = "[" + ",".join(repr(float(v)) for v in vector) + "]"
        try:
            async with self._engine.begin() as conn:
                await conn.execute(
                    text("SELECT set_config('app.tenant_id', :tenant, true)"),
                    {"tenant": str(tenant_id.value)},
                )
                await conn.execute(
                    text("SELECT set_config('hnsw.iterative_scan', 'relaxed_order', true)")
                )
                rows = (
                    await conn.execute(
                        _SEARCH,
                        {
                            "vector": literal,
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

    async def aclose(self) -> None:
        await self._engine.dispose()


def _chunk(row: Any) -> KnowledgeChunk:
    metadata = row["metadata"] if isinstance(row["metadata"], dict) else {}
    return KnowledgeChunk(
        chunk_id=int(row["id"]),
        document_id=int(row["document_id"]),
        title=str(row["title"]),
        content=str(row["content"]),
        similarity=round(float(row["similarity"]), 4),
        metadata=metadata,
    )
