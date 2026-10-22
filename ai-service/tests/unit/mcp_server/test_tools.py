from dataclasses import dataclass
from datetime import UTC, datetime

import pytest

from ai_service.mcp_server.integrations.contracts import (
    NO_INTEGRATIONS,
    IntegrationError,
    TenantIntegrations,
)
from ai_service.mcp_server.integrations.models import (
    Appointment,
    CreatedTicket,
    Invoice,
    NewTicket,
    Order,
    Ticket,
)
from ai_service.mcp_server.tools import (
    APPOINTMENT_NOT_FOUND,
    INVOICE_NOT_FOUND,
    NO_HELPDESK,
    NOT_FOUND,
    BusinessTools,
    ToolInputError,
    normalize_order_number,
)
from tests.support.mcp import CLINIC, demo_integrations, demo_tools

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

    shop = await tools.availability(1)
    assert {k for k, v in shop.items() if not v} == {"get_appointment", "get_customer_appointments"}
    only_helpdesk = await tools.availability(2)
    assert only_helpdesk["get_customer_tickets"] is True
    assert only_helpdesk["get_order"] is False
    assert only_helpdesk["get_invoice"] is False
    assert only_helpdesk["get_appointment"] is False
    assert set((await tools.availability(3)).values()) == {False}
    clinic = await tools.availability(CLINIC)
    assert clinic["get_appointment"] is clinic["get_customer_appointments"] is True
    assert clinic["get_order"] is clinic["get_invoice"] is False


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


class LeakyAppointments:
    async def find_appointment(
        self, appointment_id: str, customer_email: str
    ) -> Appointment | None:
        return Appointment(
            id=appointment_id, customer_email=AJENO, status="scheduled", starts_at=_FUTURE
        )

    async def customer_appointments(self, customer_email: str, limit: int) -> list[Appointment]:
        return [Appointment(id="C-1", customer_email=AJENO, status="scheduled", starts_at=_FUTURE)]


_FUTURE = "2026-11-05T17:00:00+01:00"


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
    tools = BusinessTools(
        Fixed(
            TenantIntegrations(LeakyOrders(), LeakyInvoices(), LeakyTickets(), LeakyAppointments())
        )
    )

    with pytest.raises(ToolInputError, match=NOT_FOUND):
        await tools.get_order(1, CLIENTE, "45821")
    with pytest.raises(ToolInputError, match=INVOICE_NOT_FOUND):
        await tools.get_invoice(1, CLIENTE, "FAC-1")
    assert (await tools.get_customer_orders(1, CLIENTE, 5))["orders"] == []
    assert (await tools.get_customer_invoices(1, CLIENTE, 5))["invoices"] == []
    assert (await tools.get_customer_tickets(1, CLIENTE, 5))["tickets"] == []
    assert (await tools.get_customer(1, CLIENTE))["found"] is False
    with pytest.raises(ToolInputError, match=APPOINTMENT_NOT_FOUND):
        await tools.get_appointment(1, CLIENTE, "C-1")
    assert (await tools.get_customer_appointments(1, CLIENTE, 5))["appointments"] == []


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


# Citas (Fase 14): un negocio sin pedidos, como una clínica.
MARIA = "maria@cliente.test"
NOW = datetime(2026, 10, 22, 12, 0, tzinfo=UTC)


def clinic_tools() -> BusinessTools:
    return BusinessTools(demo_integrations(), clock=lambda: NOW)


@pytest.mark.parametrize("raw", ["C-2031", "c-2031", "la cita C-2031"])
async def test_returns_the_appointment_of_the_customer(raw: str) -> None:
    appointment = await clinic_tools().get_appointment(CLINIC, " Maria@Cliente.TEST ", raw)

    assert appointment == {
        "appointment_id": "C-2031",
        "status": "scheduled",
        "status_label": "Programada",
        "upcoming": True,
        "starts_at": "2026-10-29T10:30:00+01:00",
        "ends_at": "2026-10-29T11:00:00+01:00",
        "service": "Limpieza dental",
        "professional": "Dra. López",
        "location": "Clínica Centro, consulta 2",
        "instructions": "Si llevas aparato, trae la funda.",
        "price": 45.0,
        "currency": "EUR",
    }  # sin el email ni el nombre: solo lo necesario para responder


@pytest.mark.parametrize(
    ("tenant", "customer", "appointment_id"),
    [
        (CLINIC, "javier@cliente.test", "C-2031"),  # cita de María
        (CLINIC, MARIA, "C-9999"),  # no existe
        (CLINIC, MARIA, "sin identificador"),
        (1, MARIA, "C-2031"),  # otra empresa (sin citas)
        (3, MARIA, "C-2031"),  # empresa desconocida
    ],
)
async def test_other_customers_or_tenants_get_the_same_appointment_not_found(
    tenant: int, customer: str, appointment_id: str
) -> None:
    with pytest.raises(ToolInputError, match=APPOINTMENT_NOT_FOUND):
        await clinic_tools().get_appointment(tenant, customer, appointment_id)


async def test_customer_appointments_are_only_theirs_upcoming_first() -> None:
    result = await clinic_tools().get_customer_appointments(CLINIC, MARIA, 5)

    assert [(a["appointment_id"], a["upcoming"]) for a in result["appointments"]] == [
        ("C-2031", True),
        ("C-1877", False),
    ]
    assert "note" not in result


@pytest.mark.parametrize(
    ("customer", "status", "label"),
    [
        ("javier@cliente.test", "no_show", "El cliente no acudió"),
        ("ana@cliente.test", "cancelled", "Cancelada"),
    ],
)
async def test_only_scheduled_future_appointments_are_upcoming(
    customer: str, status: str, label: str
) -> None:
    result = await clinic_tools().get_customer_appointments(CLINIC, customer, 5)
    past_or_cancelled = [a for a in result["appointments"] if a["status"] == status]

    assert past_or_cancelled[0]["status_label"] == label
    assert past_or_cancelled[0]["upcoming"] is False


async def test_a_scheduled_appointment_already_started_is_not_upcoming() -> None:
    later = BusinessTools(demo_integrations(), clock=lambda: datetime(2026, 12, 1, tzinfo=UTC))

    result = await later.get_customer_appointments(CLINIC, MARIA, 5)

    assert [a["upcoming"] for a in result["appointments"]] == [False, False]


async def test_customer_without_appointments_gets_a_note() -> None:
    result = await clinic_tools().get_customer_appointments(CLINIC, "nadie@cliente.test", 5)

    assert result["appointments"] == []
    assert "No inventes" in result["note"]


async def test_appointment_limit_is_bounded() -> None:
    result = await clinic_tools().get_customer_appointments(CLINIC, MARIA, 1)

    assert len(result["appointments"]) == 1
