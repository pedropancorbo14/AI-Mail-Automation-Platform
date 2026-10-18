"""API interna síncrona para Laravel (`/internal/v1`): lo que el panel
necesita al momento y no puede esperar a un evento.

- `POST /internal/v1/knowledge/search`: "Probar la búsqueda" del panel y
  `kb:search` (la búsqueda en la documentación es de ai-service).
- `GET /internal/v1/tools?tenant_id=`: catálogo de herramientas con su
  disponibilidad para la empresa (si tiene datos que consultar).

Solo red interna y con token (`AI_SERVICE_INTERNAL_TOKEN`, comparado en
tiempo constante). La empresa la pone Laravel, que ya autenticó a la persona
y comprobó sus permisos: aquí nunca se cruzan datos de otra empresa porque
toda consulta se acota a `tenant_id`.
"""

import hmac
from collections.abc import Callable
from dataclasses import asdict
from typing import Annotated, Any

from fastapi import APIRouter, Depends, Header, HTTPException, Query
from pydantic import BaseModel, Field

from ai_service.application.services.knowledge import KnowledgeRetriever
from ai_service.application.services.tool_catalog import SEARCH_TOOL, SPECS
from ai_service.domain.email import TenantId
from ai_service.infrastructure.tools.mcp_client import WRITE_TOOLS, McpToolClient


class SearchRequest(BaseModel):
    tenant_id: int = Field(ge=1)
    query: str = Field(min_length=1, max_length=2000)
    limit: int = Field(default=5, ge=1, le=20)


def internal_router(
    token: str,
    retriever: Callable[[], KnowledgeRetriever | None],
    tools: Callable[[], McpToolClient | None],
) -> APIRouter:
    expected = f"Bearer {token}".encode()

    def authorized(authorization: Annotated[str, Header()] = "") -> None:
        if not hmac.compare_digest(authorization.encode(), expected):
            raise HTTPException(status_code=401, detail="unauthorized")

    router = APIRouter(prefix="/internal/v1", tags=["internal"], dependencies=[Depends(authorized)])

    @router.post("/knowledge/search")
    async def search(payload: SearchRequest) -> dict[str, Any]:
        rag = retriever()
        if rag is None:
            raise HTTPException(status_code=503, detail="La búsqueda no está configurada.")
        result = await rag.search(TenantId(payload.tenant_id), payload.query, payload.limit)
        if result.failed:
            raise HTTPException(status_code=503, detail=result.failed)
        return {
            "results": [
                {
                    "source": chunk.source,
                    "chunk_id": chunk.chunk_id,
                    "document_id": chunk.document_id,
                    "document_title": chunk.title,
                    "content": chunk.content,
                    "metadata": dict(chunk.metadata),
                    "similarity": round(chunk.similarity, 4),
                }
                for chunk in result.chunks
            ],
            "calls": [asdict(call) for call in result.calls],
        }

    @router.get("/tools")
    async def tool_status(tenant_id: Annotated[int, Query(ge=1)]) -> dict[str, Any]:
        client = tools()
        # Sin servidor MCP configurado, ninguna herramienta de datos tiene datos;
        # si está configurado pero no responde, la disponibilidad es desconocida.
        data = await client.availability(tenant_id) if client is not None else {}

        def available(name: str) -> bool | None:
            if name == SEARCH_TOOL:
                return retriever() is not None
            return None if data is None else bool(data.get(name))

        names = sorted(set(SPECS) | WRITE_TOOLS)
        return {
            "tenant_id": tenant_id,
            "tools": [{"name": name, "available": available(name)} for name in names],
        }

    return router
