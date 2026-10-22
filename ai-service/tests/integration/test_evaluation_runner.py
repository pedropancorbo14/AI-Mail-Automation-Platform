"""El ejecutor de la evaluación con la IA simulada: agente real, servidor MCP
real en el proceso y documentación local. Sin red ni coste."""

import logging
from collections.abc import Iterator
from pathlib import Path

import pytest

from ai_service.application.evaluation.costs import parse_pricing
from ai_service.entrypoints.evaluate import Options, evaluate, main, settings_for
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


@pytest.fixture
def restore_log_levels() -> Iterator[None]:
    # main() baja el nivel de varios loggers para todo el proceso (en la línea de
    # comandos es lo que se quiere); en los tests no debe afectar a los demás.
    names = ("uvicorn", "uvicorn.error", "httpx", "httpx2", "mcp")
    levels = {name: logging.getLogger(name).level for name in names}
    yield
    for name, level in levels.items():
        logging.getLogger(name).setLevel(level)


@pytest.mark.usefixtures("restore_log_levels")
def test_smoke_mode_only_fails_on_errors_and_compare_reads_saved_runs(tmp_path: Path) -> None:
    out = str(tmp_path)
    args = ["--provider", "simulated", "--only", "spam,prompt-injection", "--out", out]

    # La IA simulada no es apta (envía casos que no debe), pero sin errores.
    assert main([*args, "--run", "a"]) in (0, 1)
    assert main([*args, "--run", "a", "--report-only", "--smoke"]) == 0
    assert main([*args, "--run", "b"]) in (0, 1)

    assert main(["--compare", "a", "b", "--out", out]) == 0
    assert (tmp_path / "compare-a-vs-b.md").exists()
    assert main(["--compare", "a", "no-existe", "--out", out]) == 2
