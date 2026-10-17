"""Supervisor y especialistas (Fase 5): quién consulta qué y qué llega al redactor."""

from typing import Any

from ai_service.agents.email_graph import LangGraphEmailAgent
from ai_service.application.dto import ClassificationResult, DraftResult
from ai_service.domain.category import Category
from ai_service.domain.decision import Decision
from ai_service.domain.draft import Source, SourceType
from ai_service.domain.email import EmailAddress, TenantId
from ai_service.domain.policy import DecisionPolicy
from ai_service.domain.specialist import Specialist
from tests.fakes import FakeActionPort, FakeLLM, FakeToolPort, make_call
from tests.unit.domain.factories import make_classification, make_draft, make_email

ORDER = {"order_number": "45821", "status": "delivered", "status_label": "Entregado"}
INVOICE = {"invoice_number": "FAC-45821", "order_number": "45821", "total": 89.9}


def classified(**overrides: Any) -> ClassificationResult:
    return ClassificationResult(make_classification(**overrides), (make_call(),))


def drafted(**overrides: Any) -> DraftResult:
    return DraftResult(make_draft(**overrides), (make_call("ai_service.draft"),))


def agent(llm: FakeLLM, tools: FakeToolPort, **kwargs: Any) -> LangGraphEmailAgent:
    return LangGraphEmailAgent(llm, DecisionPolicy(), tools=tools, **kwargs)


async def test_a_support_email_about_an_order_consults_support_and_orders_in_parallel() -> None:
    tools = FakeToolPort(results={"get_order": ORDER, "get_customer_tickets": {"tickets": []}})
    classification = classified(category=Category.TECHNICAL_SUPPORT, order_numbers=("45821",))
    llm = FakeLLM(classifications=[classification], drafts=[drafted()])
    email = make_email(tenant_id=TenantId(7), sender=EmailAddress("marcos@cliente.test"))

    result = await agent(llm, tools).run(email)

    assert result.specialists == (Specialist.SUPPORT, Specialist.ORDERS)
    assert sorted(tool for tool, _, _ in tools.calls) == ["get_customer_tickets", "get_order"]
    # Cada especialista llama con la empresa y el cliente del email, nunca otros.
    assert {(c.tenant_id, c.customer) for _, _, c in tools.calls} == {
        (TenantId(7), EmailAddress("marcos@cliente.test"))
    }
    _, _, context = llm.draft_calls[0]
    assert context.specialists == (Specialist.SUPPORT, Specialist.ORDERS)
    assert {r.tool for r in context.tool_results} == {"get_customer_tickets", "get_order"}
    assert all(r.origin == "plan" for r in result.tool_calls)


async def test_billing_looks_up_the_mentioned_invoice_and_can_cite_it() -> None:
    tools = FakeToolPort(results={"get_invoice": INVOICE})
    classification = classified(
        category=Category.BILLING_ISSUE, order_numbers=(), invoice_numbers=("FAC-45821",)
    )
    cited = (Source(SourceType.INVOICE, "FAC-45821"),)
    llm = FakeLLM(classifications=[classification], drafts=[drafted(sources=cited)])

    result = await agent(llm, tools).run(make_email())

    assert result.specialists == (Specialist.BILLING,)
    assert [(tool, args) for tool, args, _ in tools.calls] == [
        ("get_invoice", {"invoice_number": "FAC-45821"})
    ]
    # La factura citada se consultó de verdad: no hay motivo para pasar a una persona.
    assert result.outcome.decision is Decision.AUTO_SEND


async def test_problems_of_every_specialist_reach_the_decision() -> None:
    tools = FakeToolPort(
        results={
            "get_order": "No hay ningún pedido con ese número asociado a este cliente.",
            "get_invoice": "No hay ninguna factura con ese número asociada a este cliente.",
        }
    )
    classification = classified(
        category=Category.ORDER_STATUS, order_numbers=("1",), invoice_numbers=("FAC-1",)
    )
    llm = FakeLLM(classifications=[classification], drafts=[drafted()])

    result = await agent(llm, tools).run(make_email())

    assert result.outcome.decision is Decision.HUMAN_REVIEW
    assert len(result.outcome.reasons) == 2
    assert {r.tool for r in result.tool_calls} == {"get_order", "get_invoice"}


async def test_only_the_support_specialist_proposes_actions() -> None:
    # Pedidos y facturación consultan, pero solo soporte puede proponer abrir un ticket,
    # y solo si hay checkpoints para esperar a la persona.
    tools = FakeToolPort(results={"get_order": ORDER})
    classification = classified(category=Category.ORDER_STATUS, order_numbers=("45821",))
    llm = FakeLLM(classifications=[classification], drafts=[drafted()])

    result = await agent(llm, tools, actions=FakeActionPort()).run(make_email())

    assert result.pending_action is None
    assert result.specialists == (Specialist.ORDERS,)


async def test_the_injected_email_never_reaches_the_supervisor() -> None:
    tools = FakeToolPort()
    classification = classified(
        category=Category.OTHER, injection_suspected=True, order_numbers=("45821",)
    )
    llm = FakeLLM(classifications=[classification])

    result = await agent(llm, tools).run(make_email())

    assert result.specialists == ()
    assert tools.calls == []
