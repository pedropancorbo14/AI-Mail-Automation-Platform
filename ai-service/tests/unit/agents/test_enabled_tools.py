"""Herramientas por empresa: ai-service solo usa las que Laravel activó para
la empresa del email (`enabled_tools` del evento)."""

from typing import Any

from langgraph.checkpoint.memory import InMemorySaver

from ai_service.agents.email_graph import LangGraphEmailAgent
from ai_service.application.dto import ClassificationResult, DraftResult
from ai_service.application.services.knowledge import KnowledgeRetriever
from ai_service.domain.category import Category
from ai_service.domain.policy import DecisionPolicy
from tests.fakes import (
    FakeActionPort,
    FakeEmbeddings,
    FakeLLM,
    FakeToolPort,
    FakeVectorStore,
    make_call,
    make_chunk,
)
from tests.unit.domain.factories import make_classification, make_draft, make_email

ORDER = {"order_number": "45821", "status": "shipped"}


def classified(**overrides: Any) -> ClassificationResult:
    return ClassificationResult(make_classification(**overrides), (make_call(),))


def drafted() -> DraftResult:
    return DraftResult(make_draft(), (make_call("ai_service.draft"),))


def agent(llm: FakeLLM, tools: FakeToolPort, **kwargs: Any) -> LangGraphEmailAgent:
    return LangGraphEmailAgent(llm, DecisionPolicy(), tools=tools, timeout_seconds=5, **kwargs)


async def test_a_disabled_tool_is_never_planned_nor_offered_to_the_model() -> None:
    tools = FakeToolPort(results={"get_order": ORDER})
    order = classified(category=Category.ORDER_STATUS, order_numbers=("45821",))
    llm = FakeLLM(classifications=[order], drafts=[drafted()])
    email = make_email(enabled_tools=frozenset({"get_customer_orders"}))

    await agent(llm, tools).run(email)

    # El plan pediría get_order (hay número de pedido), pero la empresa no lo tiene activado.
    assert [tool for tool, _, _ in tools.calls] == []
    assert set(llm.draft_tools[0]) <= {"get_customer_orders"}


async def test_enabled_tools_are_used_as_before() -> None:
    tools = FakeToolPort(results={"get_order": ORDER})
    order = classified(category=Category.ORDER_STATUS, order_numbers=("45821",))
    llm = FakeLLM(classifications=[order], drafts=[drafted()])
    email = make_email(enabled_tools=frozenset({"get_order", "get_customer_orders"}))

    await agent(llm, tools).run(email)

    assert [tool for tool, _, _ in tools.calls] == ["get_order"]


async def test_without_the_search_tool_the_documentation_is_not_consulted() -> None:
    store = FakeVectorStore(by_tenant={1: [make_chunk()]})
    retriever = KnowledgeRetriever(FakeEmbeddings(), store)
    llm = FakeLLM(classifications=[classified()], drafts=[drafted()])
    email = make_email(enabled_tools=frozenset())

    result = await agent(llm, FakeToolPort(), retriever=retriever).run(email)

    assert store.searches == []
    assert result.tools_used == ()
    _, _, context = llm.draft_calls[0]
    assert context.knowledge == ()
    assert "search_company_documents" not in llm.draft_tools[0]


async def test_a_write_action_is_not_proposed_if_the_company_did_not_enable_it() -> None:
    support = classified(category=Category.TECHNICAL_SUPPORT, summary="No enciende.")
    llm = FakeLLM(classifications=[support], drafts=[drafted()])
    tools = FakeToolPort(results={"get_customer_tickets": {"tickets": []}})
    actions = FakeActionPort()
    email = make_email(enabled_tools=frozenset({"get_customer_tickets"}))

    result = await agent(llm, tools, actions=actions, checkpointer=InMemorySaver()).run(
        email, thread_id="t1-e42-event"
    )

    assert result.pending_action is None
    assert actions.executed == []
