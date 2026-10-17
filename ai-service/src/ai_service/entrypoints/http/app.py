"""App FastAPI: salud, métricas y depuración (el trabajo real lo hace el consumidor)."""

from collections.abc import Awaitable, Callable

import uvicorn
from fastapi import FastAPI, Response
from fastapi.responses import JSONResponse
from pydantic import BaseModel

from ai_service import __version__
from ai_service.application.ports import EmailAgentPort
from ai_service.config import Settings, get_settings
from ai_service.entrypoints.container import build_agent
from ai_service.entrypoints.http.debug import debug_router
from ai_service.infrastructure.observability.logging import configure_logging, get_logger
from ai_service.infrastructure.observability.metrics import PROMETHEUS_CONTENT_TYPE, ServiceMetrics


class HealthResponse(BaseModel):
    status: str
    service: str
    version: str
    environment: str


def create_app(
    settings: Settings | None = None,
    metrics: ServiceMetrics | None = None,
    agent_factory: Callable[[], EmailAgentPort] | None = None,
    readiness: Callable[[], Awaitable[dict[str, bool]]] | None = None,
) -> FastAPI:
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
        """Liveness: el proceso responde (Kubernetes lo reinicia si deja de hacerlo)."""
        return HealthResponse(
            status="ok",
            service="ai-service",
            version=__version__,
            environment=settings.environment.value,
        )

    @app.get("/ready", tags=["ops"])
    async def ready() -> JSONResponse:
        """Readiness: puede trabajar (Redis responde y el bucle del consumidor gira)."""
        checks = await readiness() if readiness else {}
        ok = all(checks.values())
        return JSONResponse(
            {"status": "ok" if ok else "unavailable", "checks": checks},
            status_code=200 if ok else 503,
        )

    @app.get("/metrics", tags=["ops"])
    async def prometheus_metrics() -> Response:
        return Response(content=metrics.render(), media_type=PROMETHEUS_CONTENT_TYPE)

    if settings.debug_endpoints and not settings.is_production:
        config = settings
        app.include_router(debug_router(agent_factory or (lambda: build_agent(config))))

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
