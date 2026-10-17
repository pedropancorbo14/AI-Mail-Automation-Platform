"""App FastAPI: solo salud y métricas (el trabajo real lo hace el consumidor de eventos)."""

import uvicorn
from fastapi import FastAPI, Response
from pydantic import BaseModel

from ai_service import __version__
from ai_service.config import Settings, get_settings
from ai_service.infrastructure.observability.logging import configure_logging, get_logger
from ai_service.infrastructure.observability.metrics import PROMETHEUS_CONTENT_TYPE, ServiceMetrics


class HealthResponse(BaseModel):
    status: str
    service: str
    version: str
    environment: str


def create_app(settings: Settings | None = None, metrics: ServiceMetrics | None = None) -> FastAPI:
    settings = settings or get_settings()
    metrics = metrics or ServiceMetrics(settings.environment.value)

    app = FastAPI(
        title="ai-service",
        version=__version__,
        # La documentación interactiva solo fuera de producción.
        docs_url=None if settings.is_production else "/docs",
        redoc_url=None,
        openapi_url=None if settings.is_production else "/openapi.json",
    )

    @app.get("/health", response_model=HealthResponse, tags=["ops"])
    async def health() -> HealthResponse:
        """Liveness: el proceso responde. La disponibilidad de Redis va en /ready (tarea 1.7)."""
        return HealthResponse(
            status="ok",
            service="ai-service",
            version=__version__,
            environment=settings.environment.value,
        )

    @app.get("/metrics", tags=["ops"])
    async def prometheus_metrics() -> Response:
        return Response(content=metrics.render(), media_type=PROMETHEUS_CONTENT_TYPE)

    return app


def main() -> None:
    """`ai-service-http`: arranca la app con los logs ya en JSON."""
    settings = get_settings()
    configure_logging(settings.log_level)
    get_logger(__name__).info("http_starting", host=settings.http_host, port=settings.http_port)
    uvicorn.run(
        create_app(settings),
        host=settings.http_host,
        port=settings.http_port,
        log_config=None,  # los logs de uvicorn salen por configure_logging (JSON)
        access_log=False,  # /health y /metrics cada pocos segundos: solo ruido
        proxy_headers=False,
    )
