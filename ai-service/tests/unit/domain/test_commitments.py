import pytest

from ai_service.domain.commitments import forbidden_commitments

REFUND = "La respuesta promete un reembolso (solo puede decidirlo una persona)."
CANCEL = "La respuesta confirma una cancelación (solo puede hacerla una persona)."
APPOINTMENT = (
    "La respuesta confirma que una cita se ha reservado o cambiado"
    + " (solo puede hacerlo una persona)."
)


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


@pytest.mark.parametrize(
    ("text", "reason"),
    [
        ("Hemos cancelado tu cita del jueves.", CANCEL),
        ("Tu cita ha sido cancelada.", CANCEL),
        ("Your appointment has been cancelled.", CANCEL),
        ("Hemos cambiado tu cita al martes a las 10:00.", APPOINTMENT),
        ("Tu cita ha sido reprogramada.", APPOINTMENT),
        ("We have rescheduled your appointment.", APPOINTMENT),
    ],
)
def test_detects_confirmed_appointment_changes(text: str, reason: str) -> None:
    assert forbidden_commitments(text) == (reason,)


@pytest.mark.parametrize(
    "text",
    [
        "Tu cita es el jueves 29/10 a las 10:30.",
        "Si quieres cambiar tu cita, un compañero te ayudará.",
        "Para cancelar la cita, responde a este correo.",
    ],
)
def test_informing_about_an_appointment_is_not_a_commitment(text: str) -> None:
    assert forbidden_commitments(text) == ()


@pytest.mark.parametrize(
    "text",
    [
        # Respuestas reales de Claude en la evaluación (2026-10-22): describen el
        # proceso de la política, con una condición, no prometen nada.
        "Cuando recibamos y revisemos el producto, te devolveremos el dinero en un plazo"
        " máximo de 14 días por el mismo medio de pago.",
        "Si se confirma que el cobro está duplicado, te devolveremos el importe.",
        "Si al final el paquete se da por perdido, te enviaremos uno nuevo sin coste o te"
        " devolveremos el importe, lo que prefieras.",
        "Una vez recibido el producto, procederemos a realizar el reembolso.",
        "Once we receive the item, we will refund the full amount.",
    ],
)
def test_a_conditional_explanation_of_the_refund_process_is_not_a_promise(text: str) -> None:
    assert forbidden_commitments(text) == ()


@pytest.mark.parametrize(
    "text",
    [
        # La condición al final no convierte la promesa en una explicación.
        "Te devolveremos el dinero si nos envías el extracto.",
        # Un reembolso afirmado como hecho cuenta siempre, con o sin condición.
        "Si lo prefieres, hemos tramitado tu reembolso de 49,90 €.",
        "If you wish, your refund has been processed.",
        # Una frase condicional no tapa una promesa en otra frase.
        "Si tienes dudas, escríbenos. Te devolveremos el dinero mañana.",
    ],
)
def test_promises_outside_a_conditional_sentence_still_count(text: str) -> None:
    assert forbidden_commitments(text) == (REFUND,)
