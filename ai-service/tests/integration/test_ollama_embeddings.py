import json
import math

import httpx
import pytest

from ai_service.application.errors import (
    LLMConfigurationError,
    LLMInvalidOutputError,
    LLMUnavailableError,
)
from ai_service.infrastructure.embeddings.ollama import OllamaEmbeddings


def embeddings(handler: httpx.MockTransport, dims: int = 4) -> OllamaEmbeddings:
    client = httpx.AsyncClient(base_url="http://ollama:11434", transport=handler)
    return OllamaEmbeddings(client, model="bge-m3", dimensions=dims)


async def test_sends_the_same_request_as_laravel_and_normalizes() -> None:
    seen: list[httpx.Request] = []

    def handler(request: httpx.Request) -> httpx.Response:
        seen.append(request)
        return httpx.Response(200, json={"embeddings": [[3, 0, 4, 0]], "prompt_eval_count": 12})

    result = await embeddings(httpx.MockTransport(handler)).embed_query("¿Envíos a Canarias?")

    assert seen[0].url.path == "/api/embed"
    assert json.loads(seen[0].content) == {
        "model": "bge-m3",
        "input": ["¿Envíos a Canarias?"],
        "truncate": True,
    }
    assert result.vector == (0.6, 0.0, 0.8, 0.0)
    assert math.isclose(sum(v * v for v in result.vector), 1.0)
    call = result.call
    assert (call.provider, call.model, call.status) == ("ollama", "bge-m3", "success")
    assert call.purpose == "ai_service.knowledge_search"
    assert call.input_tokens == 12


async def test_wrong_dimensions_are_rejected() -> None:
    transport = httpx.MockTransport(lambda _: httpx.Response(200, json={"embeddings": [[1, 2, 3]]}))

    with pytest.raises(LLMInvalidOutputError, match="espera 4"):
        await embeddings(transport).embed_query("x")


@pytest.mark.parametrize(
    ("response", "error", "message"),
    [
        (
            httpx.Response(404, json={"error": "model not found"}),
            LLMConfigurationError,
            "ollama pull bge-m3",
        ),
        (httpx.Response(500), LLMUnavailableError, "no está disponible"),
        (httpx.Response(400), LLMConfigurationError, "rechazó"),
    ],
)
async def test_http_errors_are_translated(
    response: httpx.Response, error: type[Exception], message: str
) -> None:
    with pytest.raises(error, match=message):
        await embeddings(httpx.MockTransport(lambda _: response)).embed_query("x")


async def test_connection_errors_are_retryable_and_billed_as_failed() -> None:
    def refuse(request: httpx.Request) -> httpx.Response:
        raise httpx.ConnectError("refused", request=request)

    with pytest.raises(LLMUnavailableError, match="ollama") as caught:
        await embeddings(httpx.MockTransport(refuse)).embed_query("x")

    assert caught.value.retryable
    assert caught.value.calls[0].status == "failed"
