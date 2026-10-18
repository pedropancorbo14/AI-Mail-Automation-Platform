"""`ai-service-tool`: ejecutar una herramienta a mano por el mismo cliente que la IA."""

import pytest

from ai_service.config import get_settings
from ai_service.entrypoints import tool_cli
from tests.integration.test_mcp_server import TOKEN, running


@pytest.fixture(autouse=True)
def fresh_settings() -> None:
    get_settings.cache_clear()


async def test_lists_the_read_tools() -> None:
    code, output = await tool_cli.run(tool_cli.parse(["--list"]))
    assert code == 0
    assert isinstance(output, list)
    assert "get_order" in {tool["name"] for tool in output}


async def test_runs_a_tool_for_a_tenant_and_customer(monkeypatch: pytest.MonkeyPatch) -> None:
    async with running(18140) as url:
        monkeypatch.setenv("AI_SERVICE_MCP_URL", url)
        monkeypatch.setenv("AI_SERVICE_MCP_TOKEN", TOKEN)
        args = ["get_order", "--tenant", "1", "--customer", "cliente@cliente.test"]
        code, own = await tool_cli.run(
            tool_cli.parse([*args, "--args", '{"order_number": "45821"}'])
        )
        _, other = await tool_cli.run(
            tool_cli.parse([*args, "--args", '{"order_number": "76540"}'])
        )

    assert code == 0
    assert isinstance(own, dict)
    assert own["status"] == "success"
    # El pedido de otro cliente no se ve, igual que para la IA.
    assert isinstance(other, dict)
    assert other["status"] == "error"


async def test_explains_missing_options() -> None:
    code, output = await tool_cli.run(tool_cli.parse(["get_order"]))
    assert code == 2
    assert output == {"error": "Indica --tenant y --customer."}
