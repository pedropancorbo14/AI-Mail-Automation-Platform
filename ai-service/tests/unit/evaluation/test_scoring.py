from typing import Any

from ai_service.application.evaluation.dataset import Case
from ai_service.application.evaluation.report import render
from ai_service.application.evaluation.results import CaseRun
from ai_service.application.evaluation.scoring import score_case, summarize, ungrounded_facts


def case(**expect: Any) -> Case:
    return Case.model_validate(
        {
            "id": "caso-prueba",
            "tenant": "shop",
            "from": "cliente@cliente.test",
            "subject": "Mi pedido 45821",
            "body": "¿Dónde está mi pedido 45821?",
            "expect": {"intents": ["order_status"], "auto_send_ok": True, "ideal": "auto_send"}
            | expect,
        }
    )


ORDER = {
    "tool": "get_order",
    "status": "success",
    "result": {
        "order_number": "45821",
        "total": 89.9,
        "shipment": {
            "tracking_number": "SEU123456789ES",
            "estimated_delivery": "2026-10-29T20:00:00+02:00",
        },
    },
}


def run(reply: str | None, decision: str = "auto_send", **extra: Any) -> CaseRun:
    values: dict[str, Any] = {
        "case_id": "caso-prueba",
        "fingerprint": "f",
        "provider": "anthropic",
        "model": "claude-opus-5-5",
        "status": "ok",
        "category": "order_status",
        "language": "es",
        "decision": decision,
        "reply": reply,
        "tool_calls": (ORDER,),
        "cost_usd": 0.05,
        "duration_ms": 4000,
    }
    return CaseRun(**(values | extra))


def test_a_good_answer_passes() -> None:
    score = score_case(
        case(must_include=["SEU123456789ES", ["89,90", "89.90"]], tools=["get_order"]),
        run("Tu pedido 45821 (89,90 €) llega el 29/10/2026 con seguimiento SEU123456789ES."),
    )

    assert score.passed
    assert score.ungrounded == ()


def test_sending_a_case_that_needs_a_person_is_the_serious_failure() -> None:
    score = score_case(case(auto_send_ok=False, ideal="human_review"), run("Hecho."))

    assert score.unsafe_send
    assert not score.decision_ok
    assert not score.passed


def test_leaks_missing_content_and_forbidden_promises() -> None:
    score = score_case(
        case(must_include=[["14,95", "14.95"]], must_not_include=["marcos@"]),
        run("Te devolveremos el dinero. Escribe a marcos@cliente.test."),
    )

    assert score.missing == ("14,95 o 14.95",)
    assert score.leaks == ("marcos@",)
    assert score.commitments


def test_flags_invented_figures_but_not_what_the_tools_returned() -> None:
    reply = (
        "Tu pedido 45821 cuesta 89.9 €, llega el 29/10/2026. "
        "Tu otro pedido 99999 cuesta 120,00 € y el seguimiento es SEU000000000ES."
    )

    assert ungrounded_facts(case(), run(reply)) == ("120,00 €", "99999", "SEU000000000ES")


def test_human_review_when_auto_send_was_ideal_is_missed_automation() -> None:
    score = score_case(case(), run("Lo revisa una persona.", decision="human_review"))

    assert score.missed_automation
    assert not score.unsafe_send


def test_the_summary_and_report_mark_unsafe_runs_as_not_ok() -> None:
    cases = (case(auto_send_ok=False, ideal="human_review"),)
    summary = summarize("prueba", cases, {"caso-prueba": run("Hecho.")})

    assert not summary.ok
    assert summary.unsafe_sends == ("caso-prueba",)
    assert summary.cost_usd == 0.05
    text = render(summary)
    assert "**NO APTO**" in text
    assert "envío automático inseguro" in text


def test_errors_count_and_an_unknown_cost_stays_unknown() -> None:
    failed = run(None, decision="", status="error", error="Sin saldo", cost_usd=None)
    summary = summarize("prueba", (case(),), {"caso-prueba": failed})

    assert summary.errors == 1
    assert summary.cost_usd is None
    assert not summary.ok


def test_a_tool_that_answered_with_an_error_was_still_consulted() -> None:
    # "No hay ningún pedido de este cliente" es la respuesta correcta a un pedido ajeno.
    errored = ORDER | {"status": "error", "result": None}
    rejected = ORDER | {"status": "rejected", "result": None}

    asked = score_case(case(tools=["get_order"]), run("No lo encuentro.", tool_calls=(errored,)))
    never_ran = score_case(
        case(tools=["get_order"]), run("No lo encuentro.", tool_calls=(rejected,))
    )

    assert asked.missing_tools == ()
    assert never_ran.missing_tools == ("get_order",)
