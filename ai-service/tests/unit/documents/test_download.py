"""Descarga del original desde Laravel: solo la ruta firmada, host de la
configuración, tamaño máximo y errores bien clasificados."""

import httpx
import pytest

from ai_service.application.errors import DocumentDownloadError, DocumentProcessingError
from ai_service.infrastructure.documents.download import LaravelDocumentSource

PATH = "/internal/documents/7/content?expires=1&signature=abc"


def source(handler: httpx.MockTransport, max_bytes: int = 1000) -> LaravelDocumentSource:
    return LaravelDocumentSource("http://nginx", max_bytes=max_bytes, transport=handler)


async def test_downloads_from_the_configured_laravel_url() -> None:
    seen: list[str] = []

    def handler(request: httpx.Request) -> httpx.Response:
        seen.append(str(request.url))
        return httpx.Response(200, content=b"# Envios")

    assert await source(httpx.MockTransport(handler)).fetch(PATH) == b"# Envios"
    assert seen == ["http://nginx" + PATH]


@pytest.mark.parametrize(
    "path",
    [
        "http://169.254.169.254/x",
        "//otro.host/internal/documents/1/content",
        "/internal/documents/../x",
        "/api/v1/users",
    ],
)
async def test_only_signed_document_paths_are_accepted(path: str) -> None:
    transport = httpx.MockTransport(lambda _: pytest.fail("no debe conectarse"))
    with pytest.raises(DocumentProcessingError, match="no válida"):
        await source(transport).fetch(path)


@pytest.mark.parametrize(
    ("status", "error", "message"),
    [
        (403, DocumentProcessingError, "caducó"),
        (404, DocumentProcessingError, "ya no existe"),
        (502, DocumentDownloadError, "502"),
    ],
)
async def test_errors(status: int, error: type[Exception], message: str) -> None:
    transport = httpx.MockTransport(lambda _: httpx.Response(status))
    with pytest.raises(error, match=message):
        await source(transport).fetch(PATH)


async def test_too_large_documents_are_rejected_while_downloading() -> None:
    transport = httpx.MockTransport(lambda _: httpx.Response(200, content=b"x" * 2000))
    with pytest.raises(DocumentProcessingError) as error:
        await source(transport, max_bytes=1000).fetch(PATH)
    assert error.value.code == "too_large"


async def test_network_errors_are_temporary() -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        raise httpx.ConnectError("caído", request=request)

    with pytest.raises(DocumentDownloadError):
        await source(httpx.MockTransport(handler)).fetch(PATH)
