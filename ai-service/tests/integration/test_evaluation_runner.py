"""El ejecutor de la evaluación con la IA simulada: agente real, servidor MCP
real en el proceso y documentación local. Sin red ni coste."""

from pathlib import Path

from ai_service.application.evaluation.costs import parse_pricing
from ai_service.entrypoints.evaluate import Options, evaluate, settings_for
from ai_service.infrastructure.llm.simulated import SimulatedLLM

REPO = Path(__file__).parents[3]


def options(tmp_path: Path, **overrides: object) -> Options:
    values: dict[str, object] = {
        "provider": "simulated",
        "model": "simulated",
        "run": "prueba",
        "dataset": REPO / "ai-service" / "evals" / "dataset.json",
        "out": tmp_path,
        "only": {"pedido-propio-45821", "envio-canarias", "cita-hora-maria", "pedido-ajeno-45821"},
        "limit": None,
        "max_cost_usd": 5.0,
        "fresh": False,
    }
    return Options(**{**values, **overrides})  # type: ignore[arg-type]


async def test_runs_the_real_agent_with_mcp_data_and_documentation(tmp_path: Path) -> None:
    prices = parse_pricing((REPO / "config" / "ai.php").read_text("utf-8"))
    settings = settings_for("simulated", "simulated")

    runs = {
        r.case_id: r for r in await evaluate(options(tmp_path), SimulatedLLM(), settings, prices)
    }

    assert set(runs) == {
        "pedido-propio-45821",
        "envio-canarias",
        "cita-hora-maria",
        "pedido-ajeno-45821",
    }
    assert all(r.status == "ok" for r in runs.values())
    # Datos de las herramientas reales (servidor MCP) y de la documentación local.
    assert "SEU123456789ES" in (runs["pedido-propio-45821"].reply or "")
    assert "Canarias" in (runs["envio-canarias"].reply or "")  # el fragmento de envíos
    assert "10:30" in (runs["cita-hora-maria"].reply or "")
    assert runs["cita-hora-maria"].specialists == ("appointments",)
    # Un pedido de otro cliente nunca llega a la respuesta.
    assert "SEU123456789ES" not in (runs["pedido-ajeno-45821"].reply or "")
    assert all((r.cost_usd or 0) > 0 for r in runs.values())  # precio ficticio de "simulated"


async def test_cached_cases_are_not_run_again_and_the_budget_stops_the_run(tmp_path: Path) -> None:
    prices = parse_pricing((REPO / "config" / "ai.php").read_text("utf-8"))
    settings = settings_for("simulated", "simulated")
    first = await evaluate(
        options(tmp_path, only={"envio-canarias"}), SimulatedLLM(), settings, prices
    )
    lines = (tmp_path / "prueba.jsonl").read_text().splitlines()

    again = await evaluate(
        options(tmp_path, only={"envio-canarias"}), SimulatedLLM(), settings, prices
    )
    capped = await evaluate(
        options(tmp_path, run="tope", only=None, limit=10, max_cost_usd=0.0001),
        SimulatedLLM(),
        settings,
        prices,
    )

    assert again == first
    assert (tmp_path / "prueba.jsonl").read_text().splitlines() == lines  # nada nuevo escrito
    assert len(capped) == 1  # el primero se paga; antes del segundo, el tope ya se alcanzó
