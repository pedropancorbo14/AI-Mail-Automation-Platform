import httpx
import pytest

from ai_service import __version__
from ai_service.config import Environment, Settings
from ai_service.entrypoints.http.app import create_app


def client_for(settings: Settings) -> httpx.AsyncClient:
    transport = httpx.ASGITransport(app=create_app(settings))
    return httpx.AsyncClient(transport=transport, base_url="http://test")


@pytest.fixture
def local_settings() -> Settings:
    return Settings(_env_file=None, environment=Environment.LOCAL)


async def test_health_reports_service_and_version(local_settings: Settings) -> None:
    async with client_for(local_settings) as client:
        response = await client.get("/health")

    assert response.status_code == 200
    assert response.json() == {
        "status": "ok",
        "service": "ai-service",
        "version": __version__,
        "environment": "local",
    }


async def test_metrics_use_prometheus_format(local_settings: Settings) -> None:
    async with client_for(local_settings) as client:
        response = await client.get("/metrics")

    assert response.status_code == 200
    assert response.headers["content-type"].startswith("text/plain")
    assert 'aiservice_build_info{environment="local"' in response.text
    assert "process_cpu_seconds_total" in response.text or "python_info" in response.text


async def test_each_app_has_its_own_metrics_registry(local_settings: Settings) -> None:
    # Dos apps en el mismo proceso no chocan (sin registro global compartido).
    create_app(local_settings)
    async with client_for(local_settings) as client:
        assert (await client.get("/metrics")).status_code == 200


async def test_interactive_docs_only_outside_production(local_settings: Settings) -> None:
    async with client_for(local_settings) as client:
        assert (await client.get("/docs")).status_code == 200

    async with client_for(Settings(_env_file=None, environment=Environment.PRODUCTION)) as client:
        assert (await client.get("/docs")).status_code == 404
        assert (await client.get("/openapi.json")).status_code == 404
