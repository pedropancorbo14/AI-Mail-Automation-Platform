from dataclasses import dataclass

import pytest

from ai_service.mcp_server.integrations.contracts import (
    NO_INTEGRATIONS,
    IntegrationError,
    TenantIntegrations,
)
from ai_service.mcp_server.integrations.models import (
    CreatedTicket,
    Invoice,
    NewTicket,
    Order,
    Ticket,
)
from ai_service.mcp_server.tools import (
    INVOICE_NOT_FOUND,
    NO_HELPDESK,
    NOT_FOUND,
    BusinessTools,
    ToolInputError,
    normalize_order_number,
)
from tests.support.mcp import demo_tools

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


async def test_returns_the_order_of_the_customer() -> None:
    order = await demo_tools().get_order(1, " Cliente@Cliente.TEST ", "#45821")

    assert order["status_label"] == "Enviado"
    assert order["shipment"]["tracking_number"] == "SEU123456789ES"
    assert order["items"][0] == {
        "name": "Lámpara de mesa Nórdica",
        "quantity": 1,
        "unit_price": 59.9,
        "sku": "LMP-200",
    }
    assert "customer_email" not in order  # solo lo necesario para responder


@pytest.mark.parametrize(
    ("tenant", "customer", "number"),
    [
        (1, CLIENTE, "76540"),  # existe, pero es de otro cliente
        (1, CLIENTE, "99999"),  # no existe
        (1, CLIENTE, "sin número"),
        (2, CLIENTE, "45821"),  # otra empresa (sin pedidos)
        (3, CLIENTE, "45821"),  # empresa sin nada conectado
    ],
)
async def test_other_customers_or_tenants_get_the_same_not_found(
    tenant: int, customer: str, number: str
) -> None:
    with pytest.raises(ToolInputError, match=NOT_FOUND):
        await demo_tools().get_order(tenant, customer, number)


async def test_customer_orders_are_only_theirs_newest_first() -> None:
    result = await demo_tools().get_customer_orders(1, "laura@cliente.test", 10)

    assert [o["order_number"] for o in result["orders"]] == ["78123", "74400"]


async def test_customer_without_orders_gets_a_note() -> None:
    result = await demo_tools().get_customer_orders(1, "nadie@cliente.test", 5)

    assert result["orders"] == []
    assert "No inventes" in result["note"]


async def test_customer_summary_and_tickets_are_scoped() -> None:
    tools = demo_tools()

    assert (await tools.get_customer(1, CLIENTE))["orders_count"] == 2
    assert (await tools.get_customer(2, CLIENTE))["found"] is False
    tickets = (await tools.get_customer_tickets(1, CLIENTE, 5))["tickets"]
    assert [t["id"] for t in tickets] == ["TCK-1042", "TCK-0981"]
    assert (await tools.get_customer_tickets(1, "marcos@cliente.test", 5))["tickets"] == []


async def test_creating_a_ticket_is_idempotent_and_visible_to_the_customer() -> None:
    tools = demo_tools()
    marcos = "marcos@cliente.test"

    first = await tools.create_ticket(1, " Marcos@Cliente.test", "No enciende", "D", "high", "k-1")
    again = await tools.create_ticket(1, marcos, "No enciende", "D", "high", "k-1")
    other = await tools.create_ticket(1, marcos, "Otro fallo", "D", "normal", "k-2")

    assert first["created"] is True
    assert first["ticket"]["priority"] == "high"
    assert again == {"created": False, "ticket": first["ticket"]}
    assert other["ticket"]["id"] != first["ticket"]["id"]
    assert "customer_email" not in first["ticket"]
    listed = (await tools.get_customer_tickets(1, marcos, 10))["tickets"]
    assert {t["id"] for t in listed} == {first["ticket"]["id"], other["ticket"]["id"]}


@pytest.mark.parametrize(
    ("tenant_id", "subject", "priority", "message"),
    [
        (3, "Asunto", "normal", NO_HELPDESK),
        (1, "", "normal", "Asunto"),
        (1, "x" * 151, "normal", "Asunto"),
        (1, "Asunto", "urgent", "Prioridad"),
    ],
    ids=["tenant-without-helpdesk", "empty-subject", "long-subject", "bad-priority"],
)
async def test_invalid_tickets_are_refused(
    tenant_id: int, subject: str, priority: str, message: str
) -> None:
    with pytest.raises(ToolInputError, match=message):
        await demo_tools().create_ticket(
            tenant_id, "marcos@cliente.test", subject, "D", priority, "k"
        )


@pytest.mark.parametrize("number", ["FAC-44102", "fac-44102", "factura 44102", "44102"])
async def test_returns_the_invoice_of_the_customer(number: str) -> None:
    invoice = await demo_tools().get_invoice(1, CLIENTE, number)

    assert invoice == {
        "invoice_number": "FAC-44102",
        "order_number": "44102",
        "issued_at": invoice["issued_at"],
        "total": invoice["total"],
        "currency": "EUR",
        "status": "paid",
        "status_label": "Pagada",
    }


@pytest.mark.parametrize(
    ("tenant", "customer", "number"),
    [
        (1, CLIENTE, "FAC-76540"),  # existe, pero es de otro cliente
        (1, "laura@cliente.test", "FAC-74400"),  # pedido cancelado: sin factura
        (2, CLIENTE, "FAC-44102"),  # otra empresa
    ],
)
async def test_other_customers_invoices_get_the_same_not_found(
    tenant: int, customer: str, number: str
) -> None:
    with pytest.raises(ToolInputError, match=INVOICE_NOT_FOUND):
        await demo_tools().get_invoice(tenant, customer, number)


async def test_customer_invoices_are_only_theirs_newest_first() -> None:
    tools = demo_tools()
    invoices = (await tools.get_customer_invoices(1, "laura@cliente.test", 10))["invoices"]

    # 78123 está en preparación (facturado) y 74400 cancelado (sin factura).
    assert [i["invoice_number"] for i in invoices] == ["FAC-78123"]
    assert (await tools.get_customer_invoices(1, "nadie@cliente.test", 5))["note"]


async def test_availability_follows_what_each_tenant_has_connected() -> None:
    tools = demo_tools()

    assert set((await tools.availability(1)).values()) == {True}
    only_helpdesk = await tools.availability(2)
    assert only_helpdesk["get_customer_tickets"] is True
    assert only_helpdesk["get_order"] is False
    assert only_helpdesk["get_invoice"] is False
    assert set((await tools.availability(3)).values()) == {False}


# Un proveedor con un fallo de filtrado (devuelve datos de otra persona) no
# debe poder enseñarlos: la comprobación es de BusinessTools, no del proveedor.
AJENO = "marcos@cliente.test"


class LeakyOrders:
    async def find_order(self, number: str, customer_email: str) -> Order | None:
        return Order(number=number, customer_email=AJENO, status="shipped")

    async def customer_orders(self, customer_email: str, limit: int) -> list[Order]:
        return [Order(number=str(n), customer_email=AJENO, status="shipped") for n in range(50)]


class LeakyInvoices:
    async def find_invoice(self, number: str, customer_email: str) -> Invoice | None:
        return Invoice(number=number, customer_email=AJENO, status="paid")

    async def customer_invoices(self, customer_email: str, limit: int) -> list[Invoice]:
        return [Invoice(number="FAC-1", customer_email=AJENO, status="paid")]


class LeakyTickets:
    async def customer_tickets(self, customer_email: str, limit: int) -> list[Ticket]:
        return [Ticket(id="TCK-1", customer_email=AJENO, status="open")]

    async def create_ticket(self, ticket: NewTicket) -> CreatedTicket:
        raise AssertionError("no se usa")


@dataclass
class Fixed:
    integrations: TenantIntegrations

    async def for_tenant(self, tenant_id: int) -> TenantIntegrations:
        return self.integrations


async def test_data_of_another_customer_from_a_buggy_provider_never_reaches_the_ai() -> None:
    tools = BusinessTools(Fixed(TenantIntegrations(LeakyOrders(), LeakyInvoices(), LeakyTickets())))

    with pytest.raises(ToolInputError, match=NOT_FOUND):
        await tools.get_order(1, CLIENTE, "45821")
    with pytest.raises(ToolInputError, match=INVOICE_NOT_FOUND):
        await tools.get_invoice(1, CLIENTE, "FAC-1")
    assert (await tools.get_customer_orders(1, CLIENTE, 5))["orders"] == []
    assert (await tools.get_customer_invoices(1, CLIENTE, 5))["invoices"] == []
    assert (await tools.get_customer_tickets(1, CLIENTE, 5))["tickets"] == []
    assert (await tools.get_customer(1, CLIENTE))["found"] is False


class DownOrders:
    async def find_order(self, number: str, customer_email: str) -> Order | None:
        raise IntegrationError("El sistema de pedidos de la empresa no responde.")

    async def customer_orders(self, customer_email: str, limit: int) -> list[Order]:
        raise IntegrationError("El sistema de pedidos de la empresa no responde.")


async def test_a_provider_failure_is_not_hidden_as_not_found() -> None:
    tools = BusinessTools(Fixed(TenantIntegrations(orders=DownOrders())))

    with pytest.raises(IntegrationError, match="no responde"):
        await tools.get_order(1, CLIENTE, "45821")
    assert (await tools.availability(1))["get_order"] is True


async def test_a_tenant_without_integrations_has_nothing() -> None:
    tools = BusinessTools(Fixed(NO_INTEGRATIONS))

    assert (await tools.get_customer_orders(1, CLIENTE, 5))["orders"] == []
    with pytest.raises(ToolInputError, match=NO_HELPDESK):
        await tools.create_ticket(1, CLIENTE, "Asunto", "D", "normal", "k")
