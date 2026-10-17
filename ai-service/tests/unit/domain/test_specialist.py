import pytest

from ai_service.domain.category import Category
from ai_service.domain.specialist import Specialist, primary_specialist, route
from tests.unit.domain.factories import make_classification


@pytest.mark.parametrize(
    ("category", "specialist"),
    [
        (Category.ORDER_STATUS, Specialist.ORDERS),
        (Category.RETURN_REQUEST, Specialist.ORDERS),
        (Category.CANCELLATION, Specialist.ORDERS),
        (Category.INVOICE_REQUEST, Specialist.BILLING),
        (Category.BILLING_ISSUE, Specialist.BILLING),
        (Category.TECHNICAL_SUPPORT, Specialist.SUPPORT),
        (Category.COMPLAINT, Specialist.SUPPORT),
        (Category.PRODUCT_QUESTION, Specialist.GENERAL),
        (Category.SALES_INQUIRY, Specialist.GENERAL),
        (Category.ACCOUNT_CHANGE, Specialist.GENERAL),
        (Category.OTHER, Specialist.GENERAL),
    ],
)
def test_the_intent_chooses_the_primary_specialist(
    category: Category, specialist: Specialist
) -> None:
    assert primary_specialist(category) is specialist


def test_mentioned_orders_and_invoices_add_secondary_specialists_after_the_primary() -> None:
    classification = make_classification(
        category=Category.TECHNICAL_SUPPORT,
        order_numbers=("45821",),
        invoice_numbers=("FAC-45821",),
    )

    assert route(classification) == (Specialist.SUPPORT, Specialist.ORDERS, Specialist.BILLING)


def test_a_specialist_is_never_repeated() -> None:
    orders = make_classification(category=Category.ORDER_STATUS, order_numbers=("1", "2"))
    billing = make_classification(category=Category.BILLING_ISSUE, invoice_numbers=("FAC-1",))

    assert route(orders) == (Specialist.ORDERS,)
    assert route(billing) == (Specialist.BILLING,)


def test_only_the_extracted_data_counts_not_the_text() -> None:
    # El texto pide a gritos facturación, pero sin números extraídos no se añade.
    classification = make_classification(
        category=Category.PRODUCT_QUESTION, summary="Consulta a facturación y pedidos"
    )

    assert route(classification) == (Specialist.GENERAL,)
