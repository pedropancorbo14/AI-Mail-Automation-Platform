from datetime import datetime

import pytest

from ai_service.domain.category import Category
from ai_service.domain.confidence import Confidence
from ai_service.domain.email import EmailAddress, TenantId
from ai_service.domain.errors import DomainError
from tests.unit.domain.factories import make_classification, make_draft, make_email


@pytest.mark.parametrize("value", [-0.01, 1.01, float("nan"), float("inf")])
def test_confidence_rejects_values_outside_zero_and_one(value: float) -> None:
    with pytest.raises(DomainError):
        Confidence(value)


@pytest.mark.parametrize(
    ("raw", "expected"), [(1.7, 1.0), (-3.0, 0.0), (0.42, 0.42), (float("nan"), 0.0)]
)
def test_confidence_from_a_model_is_clamped(raw: float, expected: float) -> None:
    assert Confidence.clamped(raw).value == expected


def test_confidence_compares_and_formats_like_the_panel() -> None:
    assert Confidence(0.72).is_below(Confidence(0.85))
    assert not Confidence(0.85).is_below(Confidence(0.85))
    assert str(Confidence(0.72)) == "0,72"


def test_tenant_id_must_be_positive() -> None:
    with pytest.raises(DomainError):
        TenantId(0)


def test_email_address_is_normalized() -> None:
    address = EmailAddress("  Cliente@Cliente.TEST ")

    assert address.value == "cliente@cliente.test"
    assert address.domain == "cliente.test"
    assert address == EmailAddress("cliente@cliente.test")


@pytest.mark.parametrize("value", ["", "sin-arroba", "@dominio.test", "a@", "a@b@c", "a b@c.test"])
def test_email_address_rejects_invalid_values(value: str) -> None:
    with pytest.raises(DomainError):
        EmailAddress(value)


def test_email_requires_a_timezone_aware_date() -> None:
    with pytest.raises(DomainError):
        make_email(received_at=datetime(2026, 10, 17, 10, 0))


def test_spam_and_auto_replies_never_need_a_reply() -> None:
    assert not Category.SPAM.needs_reply
    assert not Category.AUTO_REPLY.needs_reply
    assert all(c.needs_reply for c in Category if c not in (Category.SPAM, Category.AUTO_REPLY))


def test_injection_suspicion_always_requires_a_person() -> None:
    classification = make_classification(injection_suspected=True, needs_human=False)

    assert classification.needs_human
    assert classification.needs_human_reason == "Posible intento de manipular a la IA."


def test_draft_body_is_trimmed() -> None:
    assert make_draft(body="  \n Hola \n ").body == "Hola"
    assert make_draft(body="   ").is_empty
