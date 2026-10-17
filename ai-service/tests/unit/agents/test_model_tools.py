"""Redactor agéntico (Fase 5): consultas que pide el modelo al redactar."""

from typing import Any

from ai_service.agents.email_graph import LangGraphEmailAgent
from ai_service.application.dto import (
    ClassificationResult,
    DraftConversation,
    DraftResult,
    ToolRequest,
)
from ai_service.application.services.knowledge import KnowledgeRetriever
from ai_service.domain.category import Category
from ai_service.domain.decision import Decision
from ai_service.domain.draft import Source, SourceType
from ai_service.domain.email import EmailAddress, TenantId
from ai_service.domain.policy import DecisionPolicy
from tests.fakes import (
    FakeEmbeddings,
    FakeLLM,
    FakeToolPort,
    FakeVectorStore,
    make_call,
    make_chunk,
)
from tests.unit.domain.factories import make_classification, make_draft, make_email

ORDERS = {"orders": [{"order_number": "45821", "status": "shipped"}]}
ORDER = {"order_number": "45821", "status": "shipped", "shipment": {"carrier": "SEUR"}}


def classified(**overrides: Any) -> ClassificationResult:
    return ClassificationResult(make_classification(**overrides), (make_call(),))


def asks(*requests: tuple[str, dict[str, Any]]) -> DraftResult:
    """Paso del modelo que pide consultas antes de redactar."""
    return DraftResult(
        draft=None,
        calls=(make_call("ai_service.draft"),),
        tool_requests=tuple(ToolRequest(f"toolu_{i}", t, a) for i, (t, a) in enumerate(requests)),
        conversation=DraftConversation(turns=("turno opaco del modelo",)),
    )


def writes(**overrides: Any) -> DraftResult:
    return DraftResult(make_draft(**overrides), (make_call("ai_service.draft"),))


def order_question() -> ClassificationResult:
    return classified(category=Category.ORDER_STATUS, order_numbers=())


def agent(llm: FakeLLM, tools: FakeToolPort, **kwargs: Any) -> LangGraphEmailAgent:
    return LangGraphEmailAgent(llm, DecisionPolicy(), tools=tools, **kwargs)


async def test_the_model_asks_for_an_order_detail_and_drafts_with_it() -> None:
    tools = FakeToolPort(results={"get_customer_orders": ORDERS, "get_order": ORDER})
    cited = (Source(SourceType.ORDER, "45821"),)
    llm = FakeLLM(
        classifications=[order_question()],
        drafts=[asks(("get_order", {"order_number": "45821"})), writes(sources=cited)],
    )
    email = make_email(tenant_id=TenantId(7), sender=EmailAddress("laura@cliente.test"))

    result = await agent(llm, tools).run(email)

    # Plan: la lista de pedidos; el modelo: el detalle, con la empresa y el cliente del email.
    assert [(t, a) for t, a, _ in tools.calls] == [
        ("get_customer_orders", {"limit": 5}),
        ("get_order", {"order_number": "45821"}),
    ]
    assert tools.calls[1][2].tenant_id == TenantId(7)
    assert tools.calls[1][2].customer == EmailAddress("laura@cliente.test")
    assert [(r.tool, r.origin) for r in result.tool_calls] == [
        ("get_customer_orders", "plan"),
        ("get_order", "model"),
    ]
    # Solo se le ofrecen las herramientas de los especialistas del email (pedidos).
    assert llm.draft_tools[0] == ("get_customer_orders", "get_order")
    assert llm.draft_tools[0] == llm.draft_tools[1]  # no cambian durante la conversación
    [answer] = llm.draft_answers[1]
    assert answer.request.call_id == "toolu_0"
    assert answer.record.result == ORDER
    # Citar lo que el modelo consultó por su cuenta también es correcto.
    assert result.outcome.decision is Decision.AUTO_SEND
    assert [c.purpose for c in result.calls] == [
        "ai_service.classification",
        "ai_service.draft",
        "ai_service.draft",
    ]


async def test_a_tool_of_another_domain_is_refused_and_goes_to_a_person() -> None:
    tools = FakeToolPort(results={"get_customer_orders": ORDERS})
    llm = FakeLLM(
        classifications=[order_question()],
        drafts=[asks(("get_customer_invoices", {"limit": 5})), writes()],
    )

    result = await agent(llm, tools).run(make_email())

    assert [t for t, _, _ in tools.calls] == ["get_customer_orders"]  # nunca se llamó
    refused = result.tool_calls[-1]
    assert (refused.tool, refused.status, refused.origin) == (
        "get_customer_invoices",
        "rejected",
        "model",
    )
    assert result.outcome.decision is Decision.HUMAN_REVIEW
    assert any("pedida por la IA" in reason for reason in result.outcome.reasons)


async def test_lookups_beyond_the_email_limit_are_refused() -> None:
    tools = FakeToolPort(results={"get_customer_orders": ORDERS, "get_order": ORDER})
    llm = FakeLLM(
        classifications=[order_question()],
        drafts=[
            asks(
                ("get_order", {"order_number": "1"}),
                ("get_order", {"order_number": "2"}),
                ("get_order", {"order_number": "3"}),
            ),
            writes(),
        ],
    )

    result = await agent(llm, tools, max_model_lookups=2).run(make_email())

    model = [r for r in result.tool_calls if r.origin == "model"]
    assert [r.status for r in model] == ["success", "success", "rejected"]
    assert len(tools.calls) == 3  # el plan + 2 del modelo


async def test_a_model_that_keeps_asking_is_stopped_after_its_rounds() -> None:
    tools = FakeToolPort(results={"get_customer_orders": ORDERS, "get_order": ORDER})
    llm = FakeLLM(
        classifications=[order_question()],
        drafts=[
            asks(("get_order", {"order_number": "1"})),
            asks(("get_order", {"order_number": "2"})),
            asks(("get_order", {"order_number": "3"})),
        ],
    )

    result = await agent(llm, tools, max_model_rounds=2).run(make_email())

    assert result.draft is None
    assert result.outcome.decision is Decision.HUMAN_REVIEW
    assert any("siguió pidiendo consultas" in reason for reason in result.outcome.reasons)
    assert len(llm.draft_calls) == 3


async def test_without_rounds_the_model_gets_no_tools() -> None:
    tools = FakeToolPort(results={"get_customer_orders": ORDERS})
    llm = FakeLLM(classifications=[order_question()], drafts=[writes()])

    await agent(llm, tools, max_model_rounds=0).run(make_email())

    assert llm.draft_tools == [()]


async def test_the_model_can_search_the_documentation_of_its_tenant() -> None:
    store = FakeVectorStore(
        by_tenant={1: [make_chunk("Garantía")], 2: [make_chunk("Garantía de Globex")]}
    )
    retriever = KnowledgeRetriever(FakeEmbeddings(), store)
    source = make_chunk("Garantía").source
    llm = FakeLLM(
        classifications=[classified(category=Category.TECHNICAL_SUPPORT, order_numbers=())],
        drafts=[
            asks(("search_company_documents", {"query": "garantía de la lámpara"})),
            writes(sources=(Source(SourceType.DOCUMENT, source),)),
        ],
    )
    tools = FakeToolPort(results={"get_customer_tickets": {"tickets": []}})

    result = await agent(llm, tools, retriever=retriever).run(make_email())

    search = result.tool_calls[-1]
    assert (search.tool, search.origin, search.arguments) == (
        "search_company_documents",
        "model",
        {"query": "garantía de la lámpara"},
    )
    assert search.result is not None
    assert search.result["results"][0]["source"] == source
    # La fuente que encontró el modelo cuenta como consultada.
    assert result.outcome.decision is Decision.AUTO_SEND
    assert "search_company_documents" in llm.draft_tools[0]
