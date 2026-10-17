import pytest

from ai_service.mcp_server.backends import NOT_FOUND, ToolInputError, normalize_order_number
from tests.support.mcp import demo_data

CLIENTE = "cliente@cliente.test"


@pytest.mark.parametrize(
    ("raw", "number"),
    [
        ("#45821", "45821"),
        ("nº 45821", "45821"),
        ("pedido 45821", "45821"),
        ("es-1234", "ES-1234"),
        ("sin número", None),
    ],
)
def test_normalizes_order_numbers_like_laravel(raw: str, number: str | None) -> None:
    assert normalize_order_number(raw) == number


def test_returns_the_order_of_the_customer() -> None:
    order = demo_data().get_order(1, " Cliente@Cliente.TEST ", "#45821")

    assert order["status_label"] == "Enviado"
    assert order["shipment"]["tracking_number"] == "SEU123456789ES"
    assert "customer_email" not in order  # solo lo necesario para responder


@pytest.mark.parametrize(
    ("tenant", "customer", "number"),
    [
        (1, CLIENTE, "76540"),  # existe, pero es de otro cliente
        (1, CLIENTE, "99999"),  # no existe
        (2, CLIENTE, "45821"),  # otra empresa
        (3, CLIENTE, "45821"),  # empresa sin datos de demostración
    ],
)
def test_other_customers_or_tenants_get_the_same_not_found(
    tenant: int, customer: str, number: str
) -> None:
    with pytest.raises(ToolInputError, match=NOT_FOUND):
        demo_data().get_order(tenant, customer, number)


def test_customer_orders_are_only_theirs_newest_first() -> None:
    result = demo_data().get_customer_orders(1, "laura@cliente.test", 10)

    assert [o["order_number"] for o in result["orders"]] == ["78123", "74400"]


def test_customer_without_orders_gets_a_note() -> None:
    result = demo_data().get_customer_orders(1, "nadie@cliente.test", 5)

    assert result["orders"] == []
    assert "No inventes" in result["note"]


def test_customer_summary_and_tickets_are_scoped() -> None:
    data = demo_data()

    assert data.get_customer(1, CLIENTE)["orders_count"] == 2
    assert data.get_customer(2, CLIENTE)["found"] is False
    tickets = data.get_customer_tickets(1, CLIENTE, 5)["tickets"]
    assert [t["id"] for t in tickets] == ["TCK-1042", "TCK-0981"]
    assert data.get_customer_tickets(1, "marcos@cliente.test", 5)["tickets"] == []


def test_creating_a_ticket_is_idempotent_and_visible_to_the_customer() -> None:
    data = demo_data()

    first = data.create_ticket(1, "Marcos@Cliente.test ", "No enciende", "Desc.", "high", "k-1")
    again = data.create_ticket(1, "marcos@cliente.test", "No enciende", "Desc.", "high", "k-1")
    other = data.create_ticket(1, "marcos@cliente.test", "Otro fallo", "Desc.", "normal", "k-2")

    assert first["created"] is True
    assert again == {"created": False, "ticket": first["ticket"]}
    assert other["ticket"]["id"] != first["ticket"]["id"]
    assert "customer_email" not in first["ticket"]
    listed = data.get_customer_tickets(1, "marcos@cliente.test", 10)["tickets"]
    assert {t["id"] for t in listed} == {first["ticket"]["id"], other["ticket"]["id"]}


@pytest.mark.parametrize(
    ("tenant_id", "subject", "priority"),
    [(3, "Asunto", "normal"), (1, "", "normal"), (1, "x" * 151, "normal"), (1, "Asunto", "urgent")],
    ids=["tenant-without-helpdesk", "empty-subject", "long-subject", "bad-priority"],
)
def test_invalid_tickets_are_refused(tenant_id: int, subject: str, priority: str) -> None:
    with pytest.raises(ToolInputError):
        demo_data().create_ticket(tenant_id, "marcos@cliente.test", subject, "D", priority, "k")
