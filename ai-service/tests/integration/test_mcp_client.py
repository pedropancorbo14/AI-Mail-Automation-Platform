"""Cliente MCP contra el servidor MCP real (HTTP con token)."""

from ai_service.agents.email_graph import LangGraphEmailAgent
from ai_service.application.dto import ToolContext
from ai_service.domain.decision import Decision
from ai_service.domain.email import EmailAddress, TenantId
from ai_service.domain.policy import DecisionPolicy
from ai_service.infrastructure.llm.simulated import SimulatedLLM
from ai_service.infrastructure.tools.mcp_client import McpToolClient
from tests.integration.test_mcp_server import TOKEN, running
from tests.unit.domain.factories import make_email

CLIENTE = ToolContext(TenantId(1), EmailAddress("cliente@cliente.test"))
GLOBEX = ToolContext(TenantId(2), EmailAddress("cliente@cliente.test"))


async def test_calls_a_tool_injecting_tenant_and_customer() -> None:
    async with running(18120) as url:
        record = await McpToolClient(url, TOKEN).call(
            "get_order", {"order_number": "45821"}, CLIENTE
        )

    assert record.status == "success"
    assert record.result is not None
    assert record.result["shipment"]["carrier"] == "SEUR"
    # Se registra sin el contexto: lo que pidió el agente, no a quién.
    assert record.arguments == {"order_number": "45821"}
    assert record.duration_ms >= 0


async def test_another_customers_order_is_an_error_without_their_data() -> None:
    async with running(18121) as url:
        record = await McpToolClient(url, TOKEN).call(
            "get_order", {"order_number": "76540"}, CLIENTE
        )

    assert record.status == "error"
    assert record.result is None
    assert record.error is not None
    assert "No hay ningún pedido" in record.error


async def test_the_same_customer_in_another_tenant_sees_nothing() -> None:
    async with running(18122) as url:
        record = await McpToolClient(url, TOKEN).call("get_customer_orders", {}, GLOBEX)

    assert record.status == "success"
    assert record.result is not None
    assert record.result["orders"] == []


async def test_context_cannot_come_in_the_arguments() -> None:
    client = McpToolClient("http://no-se-llama", TOKEN)

    record = await client.call("get_customer_orders", {"customer_email": "otro@x.test"}, CLIENTE)

    assert record.status == "rejected"
    assert record.error == "Argumentos reservados (empresa o cliente)."


async def test_write_tools_are_not_allowed_yet() -> None:
    client = McpToolClient("http://no-se-llama", TOKEN)

    record = await client.call(
        "create_support_ticket", {"subject": "x", "description": "y"}, CLIENTE
    )

    assert record.status == "rejected"
    assert "create_support_ticket" not in client.available


async def test_a_wrong_token_or_a_down_server_is_an_error_not_an_exception() -> None:
    async with running(18123) as url:
        bad_token = await McpToolClient(url, "otro").call("get_customer", {}, CLIENTE)
    down = await McpToolClient("http://127.0.0.1:1", TOKEN, timeout_seconds=2).call(
        "get_customer", {}, CLIENTE
    )

    assert bad_token.status == "error"
    assert down.status == "error"
    assert down.error is not None
    assert "no disponible" in down.error or "tiempo agotado" in down.error


async def test_the_graph_answers_an_order_question_with_real_mcp_data() -> None:
    """Grafo + IA simulada + servidor MCP real: el pedido propio se envía solo,
    el de otro cliente va a una persona sin filtrar sus datos."""
    async with running(18125) as url:
        agent = LangGraphEmailAgent(
            SimulatedLLM(), DecisionPolicy(), tools=McpToolClient(url, TOKEN)
        )
        own = await agent.run(make_email(subject="Pedido", body="¿Dónde está mi pedido #45821?"))
        other = await agent.run(make_email(subject="Pedido", body="¿Dónde está mi pedido #76540?"))

    assert own.outcome.decision is Decision.AUTO_SEND
    assert own.draft is not None
    assert "SEU123456789ES" in own.draft.body
    assert own.tools_used == ("get_order",)
    assert other.outcome.decision is Decision.HUMAN_REVIEW
    assert other.draft is not None
    assert "Pedido #76540:" not in other.draft.body  # ni estado ni datos del pedido ajeno
    assert any("get_order no fue bien" in r for r in other.outcome.reasons)
