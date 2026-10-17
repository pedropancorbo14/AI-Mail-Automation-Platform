from typing import Any

import httpx
import pytest

from ai_service.application.dto import AgentOutcome
from ai_service.application.errors import AgentError, LLMRefusalError
from ai_service.application.ports import EmailAgentPort
from ai_service.config import Environment, Settings
from ai_service.domain.decision import Decision, DecisionOutcome
from ai_service.entrypoints.http.app import create_app
from tests.fakes import FakeAgent, make_call
from tests.unit.domain.factories import make_classification, make_draft

PAYLOAD = {"from": "cliente@cliente.test", "subject": "Envíos", "body": "¿Enviáis a Canarias?"}


def client(settings: Settings, agent: EmailAgentPort | None = None) -> httpx.AsyncClient:
    def factory() -> EmailAgentPort:
        assert agent is not None
        return agent

    app = create_app(settings, agent_factory=factory if agent else None)
    return httpx.AsyncClient(transport=httpx.ASGITransport(app=app), base_url="http://test")


def outcome() -> AgentOutcome:
    return AgentOutcome(
        classification=make_classification(),
        draft=make_draft(),
        outcome=DecisionOutcome(Decision.AUTO_SEND),
        calls=(make_call(), make_call("ai_service.draft")),
    )


async def test_disabled_by_default() -> None:
    async with client(Settings(_env_file=None)) as c:
        assert (await c.post("/debug/process", json=PAYLOAD)).status_code == 404


async def test_never_available_in_production() -> None:
    settings = Settings(_env_file=None, environment=Environment.PRODUCTION, debug_endpoints=True)

    async with client(settings, FakeAgent(outcome=outcome())) as c:
        assert (await c.post("/debug/process", json=PAYLOAD)).status_code == 404


async def test_returns_what_the_graph_decided() -> None:
    agent = FakeAgent(outcome=outcome())

    async with client(Settings(_env_file=None, debug_endpoints=True), agent) as c:
        response = await c.post("/debug/process", json=PAYLOAD | {"tenant_id": 7})

    assert response.status_code == 200
    data: dict[str, Any] = response.json()
    assert data["decision"] == "auto_send"
    assert data["category"] == "product_question"
    assert data["draft"]["body"].startswith("Hola:")
    assert [c["purpose"] for c in data["calls"]] == ["ai_service.classify", "ai_service.draft"]
    [email] = agent.seen
    assert email.tenant_id.value == 7


@pytest.mark.parametrize(
    "payload", [{"subject": "x", "body": "y"}, PAYLOAD | {"from": "no-email"}, PAYLOAD | {"x": 1}]
)
async def test_invalid_payloads_are_rejected(payload: dict[str, Any]) -> None:
    async with client(Settings(_env_file=None, debug_endpoints=True), FakeAgent()) as c:
        assert (await c.post("/debug/process", json=payload)).status_code == 422


async def test_without_api_key_it_says_so() -> None:
    # Sin agent_factory: usa el contenedor real, que exige la clave de Anthropic.
    async with client(Settings(_env_file=None, debug_endpoints=True)) as c:
        response = await c.post("/debug/process", json=PAYLOAD)

    assert response.status_code == 503
    assert "AI_SERVICE_ANTHROPIC_API_KEY" in response.json()["detail"]


async def test_model_errors_are_reported() -> None:
    agent = FakeAgent(error=AgentError(LLMRefusalError("El modelo se negó."), ()))

    async with client(Settings(_env_file=None, debug_endpoints=True), agent) as c:
        response = await c.post("/debug/process", json=PAYLOAD)

    assert response.status_code == 422
    assert response.json()["detail"] == {"error_code": "refusal", "message": "El modelo se negó."}
