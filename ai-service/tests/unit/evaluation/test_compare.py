from typing import Any

from ai_service.application.evaluation.compare import compare, render_comparison
from ai_service.application.evaluation.dataset import Case
from ai_service.application.evaluation.results import CaseRun
from ai_service.application.evaluation.scoring import summarize


def case(case_id: str, **expect: Any) -> Case:
    return Case.model_validate(
        {
            "id": case_id,
            "tenant": "shop",
            "from": "cliente@cliente.test",
            "subject": "Consulta",
            "body": "Hola",
            "expect": {"intents": ["order_status"], "auto_send_ok": True, "ideal": "auto_send"}
            | expect,
        }
    )


def run(case_id: str, decision: str, reply: str = "Hola.", **extra: Any) -> CaseRun:
    values: dict[str, Any] = {
        "case_id": case_id,
        "fingerprint": "f",
        "provider": "anthropic",
        "model": "m",
        "status": "ok",
        "category": "order_status",
        "language": "es",
        "decision": decision,
        "reply": reply,
        "cost_usd": 0.02,
        "duration_ms": 5000,
    }
    return CaseRun(**(values | extra))


CASES = (
    case("enviar"),
    case("persona", auto_send_ok=False, ideal="human_review"),
    case("solo-en-a"),
)


def test_a_new_unsafe_send_is_a_new_safety_failure() -> None:
    before = summarize(
        "a",
        CASES,
        {
            "enviar": run("enviar", "human_review"),
            "persona": run("persona", "human_review"),
            "solo-en-a": run("solo-en-a", "auto_send"),
        },
    )
    after = summarize(
        "b",
        CASES,
        {"enviar": run("enviar", "auto_send"), "persona": run("persona", "auto_send")},
    )

    comparison = compare(before, after)

    assert comparison.common == ("enviar", "persona")  # solo los que están en las dos
    assert comparison.new_safety_failures == ("persona",)
    assert not comparison.ok
    assert comparison.improvements == ("enviar",)
    assert comparison.regressions == ("persona",)
    assert [c.case_id for c in comparison.changes] == ["enviar", "persona"]
    text = render_comparison(comparison)
    assert "FALLOS DE SEGURIDAD NUEVOS" in text
    assert (
        "| `persona` | order_status | human_review → auto_send | superado | no superado |" in text
    )


def test_fixing_a_leak_is_reported_and_unchanged_cases_are_not_listed() -> None:
    leaky = case("enviar", must_not_include=["SEU123"])
    before = summarize(
        "a", (leaky,), {"enviar": run("enviar", "auto_send", reply="Seguimiento SEU123.")}
    )
    after = summarize("b", (leaky,), {"enviar": run("enviar", "auto_send", reply="Va en camino.")})

    comparison = compare(before, after)

    assert comparison.ok
    assert comparison.fixed_safety_failures == ("enviar",)
    assert comparison.changes == ()
    assert "Sin fallos de seguridad nuevos" in render_comparison(comparison)
