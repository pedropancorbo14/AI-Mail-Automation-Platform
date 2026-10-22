from pathlib import Path

from ai_service.application.evaluation.costs import cost_usd, parse_pricing
from ai_service.application.evaluation.dataset import load_dataset
from ai_service.application.evaluation.results import CaseRun, fingerprint
from tests.fakes import make_call

REPO = Path(__file__).parents[4]
DATASET = REPO / "ai-service" / "evals" / "dataset.json"


def test_prices_come_from_the_laravel_config() -> None:
    prices = parse_pricing((REPO / "config" / "ai.php").read_text("utf-8"))

    assert prices["claude-opus-5-5"].input == 4.0
    assert prices["claude-opus-5-5"].output == 20.0
    assert prices["claude-sonnet-5-5"].cache_read == 0.2
    assert "simulated" in prices


def test_cost_adds_every_call_and_local_embeddings_are_free() -> None:
    prices = parse_pricing((REPO / "config" / "ai.php").read_text("utf-8"))
    calls = [
        make_call(
            "ai_service.classification",
            model="claude-opus-5-5",
            input_tokens=1000,
            output_tokens=100,
        ),
        make_call(
            "ai_service.knowledge_search", provider="local", model="local-bow", input_tokens=50
        ),
    ]

    assert cost_usd(calls, prices) == 0.006  # (1000 x 4 + 100 x 20) por millón


def test_an_unknown_model_with_tokens_has_unknown_cost_never_zero() -> None:
    prices = parse_pricing((REPO / "config" / "ai.php").read_text("utf-8"))

    assert cost_usd([make_call("x", model="modelo-nuevo", input_tokens=10)], prices) is None


def test_the_fingerprint_changes_with_the_model_or_the_prompts() -> None:
    case = load_dataset(DATASET).cases[0]
    base = {
        "provider": "anthropic",
        "model": "claude-opus-5-5",
        "prompts": ("a@1",),
        "min_confidence": 0.85,
    }

    same = fingerprint(case, **base)  # type: ignore[arg-type]
    assert fingerprint(case, **base) == same  # type: ignore[arg-type]
    assert fingerprint(case, **{**base, "model": "claude-sonnet-5-5"}) != same  # type: ignore[arg-type]
    assert fingerprint(case, **{**base, "prompts": ("a@2",)}) != same  # type: ignore[arg-type]


def test_a_case_run_survives_a_round_trip_to_json() -> None:
    run = CaseRun(
        case_id="c",
        fingerprint="f",
        provider="simulated",
        model="simulated",
        status="ok",
        decision="auto_send",
        sources=(("order", "45821"),),
        tool_calls=({"tool": "get_order", "status": "success"},),
    )

    assert CaseRun.from_json(run.to_json()) == run
