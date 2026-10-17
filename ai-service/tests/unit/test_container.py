import pytest

from ai_service.application.errors import LLMConfigurationError
from ai_service.config import Settings
from ai_service.entrypoints.container import build_agent, build_tools


def test_without_mcp_url_the_graph_has_no_tools() -> None:
    assert build_tools(Settings()) is None


def test_mcp_needs_a_token() -> None:
    with pytest.raises(LLMConfigurationError, match="AI_SERVICE_MCP_TOKEN"):
        build_tools(Settings(mcp_url="http://ai-mcp:8100"))


def test_mcp_client_is_built_with_url_and_token() -> None:
    client = build_tools(Settings(mcp_url="http://ai-mcp:8100", mcp_token="secreto"))  # type: ignore[arg-type]

    assert client is not None
    assert "get_order" in client.available
    assert "create_support_ticket" not in client.available


def test_the_agent_gets_its_limits_from_the_settings() -> None:
    agent = build_agent(
        Settings(llm_provider="simulated", max_tokens_per_email=5_000, max_graph_steps=20)
    )

    assert agent._max_tokens == 5_000
    assert agent._max_steps == 20
