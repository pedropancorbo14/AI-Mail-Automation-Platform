import pytest

from ai_service.domain.commitments import forbidden_commitments

REFUND = "La respuesta promete un reembolso (solo puede decidirlo una persona)."
CANCEL = "La respuesta confirma una cancelación (solo puede hacerla una persona)."


@pytest.mark.parametrize(
    "text",
    [
        "Te devolveremos el dinero en 5 días.",
        "Procederemos a realizar el reembolso hoy mismo.",
        "Hemos tramitado tu reembolso de 49,90 €.",
        "Recibirás el reembolso en tu tarjeta.",
        "Tu reembolso ha sido aprobado.",
        "We will refund the full amount.",
        "We'll issue a refund today.",
        "Your refund has been processed.",
        "You will receive a full refund.",
    ],
)
def test_detects_refund_promises(text: str) -> None:
    assert forbidden_commitments(text) == (REFUND,)


@pytest.mark.parametrize(
    "text",
    [
        "Hemos cancelado tu pedido #45821.",
        "Tu pedido ha sido cancelado.",
        "We've cancelled your subscription.",
        "Your order has been canceled.",
    ],
)
def test_detects_confirmed_cancellations(text: str) -> None:
    assert forbidden_commitments(text) == (CANCEL,)


@pytest.mark.parametrize(
    "text",
    [
        # Informar de la política no es prometer nada.
        "Según nuestra política, los reembolsos se hacen en 14 días tras recibir el producto.",
        "Para solicitar un reembolso, una persona del equipo revisará tu caso.",
        "Puedes cancelar tu pedido mientras esté en preparación.",
        "Refunds are issued within 14 days of receiving the item.",
        "Tu pedido #45821 está en camino con SEUR.",
    ],
)
def test_informing_about_policies_is_not_a_commitment(text: str) -> None:
    assert forbidden_commitments(text) == ()


def test_reports_each_kind_of_commitment_once() -> None:
    text = "Hemos cancelado tu pedido y te devolveremos el dinero. Te reembolsaremos todo."

    assert forbidden_commitments(text) == (REFUND, CANCEL)
