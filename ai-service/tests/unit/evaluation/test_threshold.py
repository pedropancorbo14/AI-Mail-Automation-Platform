import pytest

from ai_service.application.evaluation.results import CaseRun
from ai_service.application.evaluation.threshold import recalculate

LOW = "La confianza en la respuesta (0,80) es menor que el mínimo (0,85)."


def run(decision: str, confidence: float | None, *reasons: str, status: str = "ok") -> CaseRun:
    return CaseRun(
        case_id="c",
        fingerprint="f",
        provider="anthropic",
        model="m",
        status=status,  # type: ignore[arg-type]
        decision=decision,
        reasons=reasons,
        reply="Hola",
        reply_confidence=confidence,
    )


def test_lowering_the_threshold_sends_a_reply_stopped_only_by_its_confidence() -> None:
    recalculated = recalculate(run("human_review", 0.80, LOW), 0.78)

    assert recalculated.decision == "auto_send"
    assert recalculated.reasons == ()


def test_any_other_reason_still_sends_it_to_a_person() -> None:
    other = "Requiere acciones que solo puede hacer una persona: abrir una incidencia."

    recalculated = recalculate(run("human_review", 0.80, LOW, other), 0.75)

    assert recalculated.decision == "human_review"
    assert recalculated.reasons == (other,)


def test_an_escalated_case_stays_escalated() -> None:
    injection = "Posible intento de manipular a la IA: no se propone respuesta."

    assert recalculate(run("escalate", 0.80, injection, LOW), 0.5).decision == "escalate"


def test_raising_the_threshold_stops_a_reply_that_was_sent() -> None:
    recalculated = recalculate(run("auto_send", 0.86), 0.90)

    assert recalculated.decision == "human_review"
    assert recalculated.reasons == (
        "La confianza en la respuesta (0,86) es menor que el mínimo (0,90).",
    )


@pytest.mark.parametrize(
    "original",
    [run("human_review", None, "La IA no preparó una respuesta."), run("x", 0.5, status="error")],
)
def test_cases_without_a_reply_or_with_errors_do_not_change(original: CaseRun) -> None:
    assert recalculate(original, 0.5) == original
