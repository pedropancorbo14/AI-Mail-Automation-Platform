"""Descarga del original de un documento desde Laravel (URL firmada temporal).

El evento solo trae la RUTA relativa (`/internal/documents/{id}/content?...`);
el host es el de la configuración (`AI_SERVICE_LARAVEL_URL`): un evento nunca
decide a qué servidor se conecta ai-service (anti-SSRF). Tamaño máximo
comprobado mientras se descarga (no se lee entero en memoria si es enorme).
"""

import httpx

from ai_service.application.errors import DocumentDownloadError, DocumentProcessingError

PREFIX = "/internal/documents/"


class LaravelDocumentSource:
    """Implementa `DocumentSourcePort`."""

    def __init__(
        self,
        base_url: str,
        *,
        timeout: float = 60.0,
        max_bytes: int = 20 * 1024**2,
        transport: httpx.AsyncBaseTransport | None = None,
    ) -> None:
        self._base = base_url.rstrip("/")
        self._timeout = timeout
        self._max = max_bytes
        self._transport = transport

    async def fetch(self, path: str) -> bytes:
        if not path.startswith(PREFIX) or "//" in path or ".." in path:
            raise DocumentProcessingError("download", "Ruta de descarga no válida.")
        try:
            async with (
                httpx.AsyncClient(
                    timeout=self._timeout, follow_redirects=False, transport=self._transport
                ) as client,
                client.stream("GET", self._base + path) as response,
            ):
                if response.status_code in (401, 403):
                    # Firma caducada o no válida: reintentar no sirve; Laravel
                    # vuelve a publicar con una URL nueva al reindexar.
                    raise DocumentProcessingError(
                        "download", "El enlace de descarga del documento caducó: reindéxalo."
                    )
                if response.status_code == 404:
                    raise DocumentProcessingError("download", "El documento ya no existe.")
                if response.status_code != 200:
                    raise DocumentDownloadError(f"Laravel respondió {response.status_code}.")
                chunks: list[bytes] = []
                size = 0
                async for chunk in response.aiter_bytes():
                    size += len(chunk)
                    if size > self._max:
                        raise DocumentProcessingError(
                            "too_large",
                            f"El documento supera el tamaño máximo ({self._max // 1024**2} MB).",
                        )
                    chunks.append(chunk)
                return b"".join(chunks)
        except httpx.HTTPError as error:
            raise DocumentDownloadError(
                f"No se pudo descargar el documento ({type(error).__name__})."
            ) from error
