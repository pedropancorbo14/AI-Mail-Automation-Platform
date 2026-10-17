"""Cliente MCP contra el servidor MCP real (HTTP con token)."""

from typing import Any

from ai_service.agents.email_graph import LangGraphEmailAgent
from ai_service.application.dto import PendingAction, ToolContext
from ai_service.domain.decision import Decision
from ai_service.domain.email import EmailAddress, TenantId
from ai_service.domain.policy import DecisionPolicy
from ai_service.infrastructure.checkpoints.postgres import PostgresCheckpoints
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
    assert record.error.startswith("No hay ningún pedido")


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


async def test_approved_actions_run_once_with_tenant_customer_and_idempotency_key() -> None:
    action = PendingAction(
        action_id="0f6a3f7c-3a43-4f3e-9c55-1a2b3c4d5e6f",
        tool="create_support_ticket",
        arguments={
            "subject": "La lámpara no enciende",
            "description": "No enciende.",
            "priority": "high",
        },
        summary="Crear un ticket",
    )
    marcos = ToolContext(TenantId(1), EmailAddress("marcos@cliente.test"))
    async with running(18126) as url:
        client = McpToolClient(url, TOKEN)
        first = await client.execute(action, marcos)
        again = await client.execute(action, marcos)  # reanudación repetida
        tickets = await client.call("get_customer_tickets", {}, marcos)

    assert first.status == "success"
    assert first.result is not None
    assert first.result["created"] is True
    ticket = first.result["ticket"]
    assert ticket["priority"] == "high"
    assert again.result == {"created": False, "ticket": ticket}
    # Se registra sin el contexto ni la clave (lo que decidió el backend).
    assert first.arguments == action.arguments
    assert tickets.result is not None
    assert [t["id"] for t in tickets.result["tickets"]] == [ticket["id"]]


async def test_write_tools_never_run_through_call_and_reserved_arguments_are_refused() -> None:
    client = McpToolClient("http://no-se-llama", TOKEN)
    action = PendingAction(
        "0f6a3f7c-3a43-4f3e-9c55-1a2b3c4d5e6f",
        "create_support_ticket",
        {"subject": "x", "description": "y", "idempotency_key": "otra"},
        "Crear",
    )

    via_call = await client.call("create_support_ticket", {"subject": "x"}, CLIENTE)
    reserved = await client.execute(action, CLIENTE)
    unknown = await client.execute(PendingAction("a", "delete_customer", {}, "Borrar"), CLIENTE)

    assert (via_call.status, via_call.error) == ("rejected", "Herramienta no permitida.")
    assert reserved.status == "rejected"
    assert (unknown.status, unknown.error) == ("rejected", "Acción no permitida.")


async def test_the_graph_waits_for_approval_across_a_restart(checkpoint_db: Any) -> None:
    """Grafo + IA simulada + servidor MCP real + checkpoints en PostgreSQL real:
    la pausa sobrevive a un reinicio del worker y, aprobada, abre el ticket una vez."""
    _, checkpoints_url = checkpoint_db
    email = make_email(
        sender=EmailAddress("marcos@cliente.test"),
        subject="La lámpara no enciende",
        body="Hola, la lámpara que compré no enciende. ¿Qué hago?",
    )
    async with running(18127) as url:
        first = await PostgresCheckpoints.open(checkpoints_url)
        client = McpToolClient(url, TOKEN)
        agent = LangGraphEmailAgent(
            SimulatedLLM(), DecisionPolicy(), tools=client, actions=client, checkpointer=first.saver
        )
        paused = await agent.run(email, thread_id="t1-e42-restart")
        await first.aclose()  # el worker se reinicia mientras la persona decide

        second = await PostgresCheckpoints.open(checkpoints_url)
        agent = LangGraphEmailAgent(
            SimulatedLLM(),
            DecisionPolicy(),
            tools=client,
            actions=client,
            checkpointer=second.saver,
        )
        assert paused.pending_action is not None
        done = await agent.resume(
            "t1-e42-restart",
            tenant_id=email.tenant_id,
            email_id=email.email_id,
            action_id=paused.pending_action.action_id,
            approved=True,
        )
        left = await second.saver.aget_tuple({"configurable": {"thread_id": "t1-e42-restart"}})
        await second.aclose()

    assert paused.outcome.decision is Decision.HUMAN_REVIEW
    assert done.draft is not None
    ticket = done.tool_calls[-1].result["ticket"]["id"]  # type: ignore[index]
    assert ticket in done.draft.body
    assert done.outcome.decision is Decision.AUTO_SEND
    assert left is None  # terminado: no queda estado con datos personales
