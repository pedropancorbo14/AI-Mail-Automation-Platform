"""API interna síncrona para Laravel (`/internal/v1`)."""

from typing import Any

import httpx
from fastapi import FastAPI

from ai_service.application.services.knowledge import KnowledgeRetriever
from ai_service.config import Environment, Settings
from ai_service.entrypoints.http.app import create_app
from ai_service.entrypoints.http.internal import internal_router
from ai_service.infrastructure.tools.mcp_client import McpToolClient
from tests.fakes import FakeEmbeddings, FakeVectorStore, make_chunk
from tests.integration.test_mcp_server import TOKEN, running

INTERNAL = "internal-secret"
AUTH = {"Authorization": f"Bearer {INTERNAL}"}


def app(retriever: KnowledgeRetriever | None = None, tools: McpToolClient | None = None) -> FastAPI:
    api = FastAPI()
    api.include_router(internal_router(INTERNAL, lambda: retriever, lambda: tools))
    return api


async def tools_of(client: McpToolClient, tenant_id: int) -> dict[str, Any]:
    path = f"/internal/v1/tools?tenant_id={tenant_id}"
    body: dict[str, Any] = (await request(app(tools=client), "GET", path, headers=AUTH)).json()
    return {t["name"]: t["available"] for t in body["tools"]}


async def request(api: FastAPI, method: str, path: str, **kwargs: Any) -> httpx.Response:
    transport = httpx.ASGITransport(app=api)
    async with httpx.AsyncClient(transport=transport, base_url="http://ai-service") as client:
        return await client.request(method, path, **kwargs)


async def test_without_token_nothing_is_answered() -> None:
    api = app()
    for headers in ({}, {"Authorization": "Bearer otro"}):
        response = await request(api, "GET", "/internal/v1/tools?tenant_id=1", headers=headers)
        assert response.status_code == 401


async def test_without_internal_token_the_api_does_not_exist() -> None:
    api = create_app(Settings(environment=Environment.TESTING))
    response = await request(api, "GET", "/internal/v1/tools?tenant_id=1", headers=AUTH)
    assert response.status_code == 404


async def test_searches_the_documentation_of_the_given_tenant_only() -> None:
    store = FakeVectorStore(by_tenant={1: [make_chunk()], 2: [make_chunk(title="De Globex")]})
    api = app(KnowledgeRetriever(FakeEmbeddings(), store))

    response = await request(
        api,
        "POST",
        "/internal/v1/knowledge/search",
        json={"tenant_id": 1, "query": "envíos a Canarias", "limit": 3},
        headers=AUTH,
    )

    assert response.status_code == 200
    body = response.json()
    assert [r["document_title"] for r in body["results"]] == [make_chunk().title]
    assert body["results"][0]["source"] == make_chunk().source
    assert [c["purpose"] for c in body["calls"]] == ["ai_service.knowledge_search"]
    assert [t.value for t in store.searches] == [1]


async def test_search_without_rag_or_with_it_down_is_503() -> None:
    payload = {"json": {"tenant_id": 1, "query": "hola"}, "headers": AUTH}
    response = await request(app(), "POST", "/internal/v1/knowledge/search", **payload)
    assert response.status_code == 503

    from ai_service.application.errors import KnowledgeBaseError

    store = FakeVectorStore(error=KnowledgeBaseError("BD caída"))
    api = app(KnowledgeRetriever(FakeEmbeddings(), store))
    response = await request(api, "POST", "/internal/v1/knowledge/search", **payload)
    assert response.status_code == 503


async def test_tools_report_whether_the_tenant_has_data() -> None:
    async with running(18130) as url:
        client = McpToolClient(url, TOKEN)
        demo = (
            await request(app(tools=client), "GET", "/internal/v1/tools?tenant_id=1", headers=AUTH)
        ).json()
        empty = (
            await request(app(tools=client), "GET", "/internal/v1/tools?tenant_id=2", headers=AUTH)
        ).json()

    available = {t["name"]: t["available"] for t in demo["tools"]}
    assert available["get_order"] is True
    assert available["create_support_ticket"] is True
    assert available["search_company_documents"] is False  # sin RAG configurado
    assert {t["name"]: t["available"] for t in empty["tools"]}["get_order"] is False


async def test_tools_are_unknown_if_the_mcp_server_does_not_answer() -> None:
    client = McpToolClient("http://127.0.0.1:1", TOKEN, timeout_seconds=1)
    body = (
        await request(app(tools=client), "GET", "/internal/v1/tools?tenant_id=1", headers=AUTH)
    ).json()
    assert {t["name"]: t["available"] for t in body["tools"]}["get_order"] is None
