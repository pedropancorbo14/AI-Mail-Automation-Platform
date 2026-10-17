import uuid

from ai_service.application.dto import ToolCallRecord
from ai_service.application.services.action_planner import plan_action
from ai_service.domain.category import Category
from ai_service.domain.classification import Classification, Urgency
from tests.unit.domain.factories import make_classification, make_email

AVAILABLE = frozenset({"create_support_ticket"})


def tickets(*statuses: str) -> ToolCallRecord:
    rows = [{"id": f"TCK-{i}", "status": s} for i, s in enumerate(statuses)]
    return ToolCallRecord("get_customer_tickets", {"limit": 5}, {"tickets": rows}, "success")


def support(**overrides: object) -> Classification:
    values: dict[str, object] = {
        "category": Category.TECHNICAL_SUPPORT,
        "summary": "La lámpara no enciende desde ayer.",
        "order_numbers": ("45821",),
    }
    return make_classification(**(values | overrides))


def test_support_without_open_tickets_proposes_one() -> None:
    email = make_email(subject="  La lámpara no enciende  ")

    action = plan_action(email, support(), [tickets("closed")], AVAILABLE)

    assert action is not None
    uuid.UUID(action.action_id)
    assert action.tool == "create_support_ticket"
    assert action.arguments == {
        "subject": "La lámpara no enciende",
        "description": "La lámpara no enciende desde ayer. Pedidos mencionados: 45821.",
        "priority": "normal",
    }
    assert action.summary == (
        "Crear un ticket de soporte «La lámpara no enciende» (prioridad normal) "
        "para cliente@cliente.test."
    )


def test_urgent_cases_get_high_priority() -> None:
    action = plan_action(make_email(), support(urgency=Urgency.HIGH), [tickets()], AVAILABLE)

    assert action is not None
    assert action.arguments["priority"] == "high"
    assert "(prioridad alta)" in action.summary


def test_nothing_is_proposed_when_the_customer_already_has_an_open_ticket() -> None:
    assert plan_action(make_email(), support(), [tickets("closed", "open")], AVAILABLE) is None


def test_nothing_is_proposed_without_knowing_the_customer_tickets() -> None:
    failed = ToolCallRecord("get_customer_tickets", {}, None, "error", "servicio caído")

    assert plan_action(make_email(), support(), [failed], AVAILABLE) is None
    assert plan_action(make_email(), support(), [], AVAILABLE) is None


def test_only_technical_support_and_only_if_the_action_is_available() -> None:
    other = make_classification(category=Category.COMPLAINT)

    assert plan_action(make_email(), other, [tickets()], AVAILABLE) is None
    assert plan_action(make_email(), support(), [tickets()], frozenset()) is None
