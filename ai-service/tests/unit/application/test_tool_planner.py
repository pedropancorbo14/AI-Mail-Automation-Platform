import pytest

from ai_service.application.services.tool_planner import (
    PlannedCall,
    plan_tool_calls,
    references_in,
)
from ai_service.domain.category import Category
from tests.unit.domain.factories import make_classification

ALL = frozenset({"get_order", "get_customer_orders", "get_customer", "get_customer_tickets"})


def test_order_questions_look_up_each_mentioned_order() -> None:
    classification = make_classification(
        category=Category.ORDER_STATUS, order_numbers=("45821", "45821", "44102")
    )

    assert plan_tool_calls(classification, ALL) == [
        PlannedCall("get_order", {"order_number": "45821"}),
        PlannedCall("get_order", {"order_number": "44102"}),
    ]


def test_order_questions_without_number_list_the_customer_orders() -> None:
    classification = make_classification(category=Category.RETURN_REQUEST, order_numbers=())

    assert plan_tool_calls(classification, ALL) == [
        PlannedCall("get_customer_orders", {"limit": 5})
    ]


def test_at_most_three_orders_per_email() -> None:
    numbers = tuple(str(n) for n in range(10000, 10010))
    classification = make_classification(category=Category.CANCELLATION, order_numbers=numbers)

    assert len(plan_tool_calls(classification, ALL)) == 3


@pytest.mark.parametrize(
    ("category", "tools"),
    [
        (Category.INVOICE_REQUEST, ["get_customer_orders"]),
        (Category.BILLING_ISSUE, ["get_customer_orders"]),
        (Category.TECHNICAL_SUPPORT, ["get_customer_tickets"]),
        (Category.COMPLAINT, ["get_customer_tickets"]),
        (Category.ACCOUNT_CHANGE, ["get_customer"]),
        (Category.PRODUCT_QUESTION, []),
        (Category.SALES_INQUIRY, []),
        (Category.SPAM, []),
    ],
)
def test_each_intent_has_its_own_plan(category: Category, tools: list[str]) -> None:
    plan = plan_tool_calls(make_classification(category=category, order_numbers=()), ALL)

    assert [c.tool for c in plan] == tools


def test_tools_not_offered_by_the_server_are_skipped() -> None:
    classification = make_classification(category=Category.COMPLAINT, order_numbers=("45821",))

    assert plan_tool_calls(classification, frozenset({"get_order"})) == [
        PlannedCall("get_order", {"order_number": "45821"})
    ]


def test_references_come_only_from_what_the_tools_returned() -> None:
    assert references_in("get_order", {"order_number": "45821"}) == {"45821"}
    assert references_in(
        "get_customer_orders", {"orders": [{"order_number": "1"}, {"order_number": "2"}]}
    ) == {"1", "2"}
    assert references_in("get_customer_tickets", {"tickets": [{"id": "T-9"}]}) == {"T-9"}
    assert references_in("get_customer", {"found": True, "email": "a@b.test"}) == {"a@b.test"}
    assert references_in("get_customer", {"found": False}) == set()
    assert references_in("get_order", None) == set()
    assert references_in("get_customer_orders", {"orders": "no es una lista"}) == set()
