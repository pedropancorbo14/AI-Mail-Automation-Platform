import pytest

from ai_service.application.services.tool_planner import (
    READ_TOOLS,
    PlannedCall,
    plan_for,
    references_in,
)
from ai_service.domain.category import Category
from ai_service.domain.specialist import Specialist
from tests.unit.domain.factories import make_classification

ALL = frozenset().union(*READ_TOOLS.values())


def test_orders_look_up_each_mentioned_order() -> None:
    classification = make_classification(
        category=Category.ORDER_STATUS, order_numbers=("45821", "45821", "44102")
    )

    assert plan_for(Specialist.ORDERS, classification, ALL, primary=True) == [
        PlannedCall("get_order", {"order_number": "45821"}),
        PlannedCall("get_order", {"order_number": "44102"}),
    ]


def test_orders_without_number_list_the_customer_orders_only_as_primary() -> None:
    classification = make_classification(category=Category.RETURN_REQUEST, order_numbers=())

    assert plan_for(Specialist.ORDERS, classification, ALL, primary=True) == [
        PlannedCall("get_customer_orders", {"limit": 5})
    ]
    assert plan_for(Specialist.ORDERS, classification, ALL, primary=False) == []


def test_at_most_three_orders_and_three_invoices_per_email() -> None:
    numbers = tuple(str(n) for n in range(10000, 10010))
    classification = make_classification(
        category=Category.CANCELLATION, order_numbers=numbers, invoice_numbers=numbers
    )

    assert len(plan_for(Specialist.ORDERS, classification, ALL, primary=True)) == 3
    assert len(plan_for(Specialist.BILLING, classification, ALL, primary=False)) == 3


def test_billing_looks_up_invoices() -> None:
    mentioned = make_classification(
        category=Category.BILLING_ISSUE, order_numbers=(), invoice_numbers=("FAC-45821",)
    )
    general = make_classification(category=Category.INVOICE_REQUEST, order_numbers=())

    assert plan_for(Specialist.BILLING, mentioned, ALL, primary=True) == [
        PlannedCall("get_invoice", {"invoice_number": "FAC-45821"})
    ]
    assert plan_for(Specialist.BILLING, general, ALL, primary=True) == [
        PlannedCall("get_customer_invoices", {"limit": 5})
    ]


@pytest.mark.parametrize(
    ("specialist", "category", "tools"),
    [
        (Specialist.SUPPORT, Category.TECHNICAL_SUPPORT, ["get_customer_tickets"]),
        (Specialist.SUPPORT, Category.COMPLAINT, ["get_customer_tickets"]),
        (Specialist.APPOINTMENTS, Category.APPOINTMENT, ["get_customer_appointments"]),
        (Specialist.GENERAL, Category.ACCOUNT_CHANGE, ["get_customer"]),
        (Specialist.GENERAL, Category.PRODUCT_QUESTION, []),
        (Specialist.GENERAL, Category.SALES_INQUIRY, []),
    ],
)
def test_each_specialist_has_its_own_plan(
    specialist: Specialist, category: Category, tools: list[str]
) -> None:
    plan = plan_for(
        specialist, make_classification(category=category, order_numbers=()), ALL, primary=True
    )

    assert [c.tool for c in plan] == tools


def test_each_read_tool_belongs_to_one_specialist() -> None:
    seen = [tool for tools in READ_TOOLS.values() for tool in tools]

    assert len(seen) == len(set(seen))


def test_tools_not_offered_by_the_server_are_skipped() -> None:
    classification = make_classification(category=Category.ORDER_STATUS, order_numbers=("45821",))

    assert (
        plan_for(Specialist.ORDERS, classification, frozenset({"get_customer"}), primary=True) == []
    )


def test_references_come_only_from_what_the_tools_returned() -> None:
    assert references_in("get_order", {"order_number": "45821"}) == {"45821"}
    assert references_in(
        "get_customer_orders", {"orders": [{"order_number": "1"}, {"order_number": "2"}]}
    ) == {"1", "2"}
    assert references_in("get_customer_tickets", {"tickets": [{"id": "T-9"}]}) == {"T-9"}
    assert references_in("get_customer", {"found": True, "email": "a@b.test"}) == {"a@b.test"}
    assert references_in("get_customer", {"found": False}) == set()
    assert references_in("get_invoice", {"invoice_number": "FAC-1"}) == {"FAC-1"}
    assert references_in("get_customer_invoices", {"invoices": [{"invoice_number": "FAC-2"}]}) == {
        "FAC-2"
    }
    assert references_in("get_appointment", {"appointment_id": "C-2031"}) == {"C-2031"}
    assert references_in(
        "get_customer_appointments", {"appointments": [{"appointment_id": "C-1877"}]}
    ) == {"C-1877"}
    assert references_in("get_order", None) == set()
    assert references_in("get_customer_orders", {"orders": "no es una lista"}) == set()
