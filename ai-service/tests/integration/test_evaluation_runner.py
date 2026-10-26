"""El ejecutor de la evaluación con la IA simulada: agente real, servidor MCP
real en el proceso y documentación local. Sin red ni coste."""

import json
import logging
from collections.abc import Iterator
from pathlib import Path

import httpx
import pytest

from ai_service.application.evaluation.costs import parse_pricing
from ai_service.domain.email import TenantId
from ai_service.entrypoints.evaluate import (
    EvaluationSetupError,
    Options,
    evaluate,
    knowledge,
    main,
    parse_args,
    settings_for,
    write_report,
)
from ai_service.infrastructure.embeddings.ollama import OllamaEmbeddings
from ai_service.infrastructure.knowledge.local import embed as local_embed
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


def fake_ollama(fail: bool = False) -> OllamaEmbeddings:
    """bge-m3 simulado: vectores de la bolsa de palabras local (512 dimensiones)."""

    def handler(request: httpx.Request) -> httpx.Response:
        if fail:
            raise httpx.ConnectError("sin conexión", request=request)
        texts = json.loads(request.content)["input"]
        return httpx.Response(200, json={"embeddings": [list(local_embed(t)) for t in texts]})

    client = httpx.AsyncClient(
        base_url="http://ollama:11434", transport=httpx.MockTransport(handler)
    )
    return OllamaEmbeddings(client, model="bge-m3", dimensions=512)


async def test_the_real_embeddings_use_production_threshold_and_their_own_cache(
    tmp_path: Path,
) -> None:
    prices = parse_pricing((REPO / "config" / "ai.php").read_text("utf-8"))
    settings = settings_for("simulated", "simulated")
    only = {"envio-canarias"}

    local = await evaluate(options(tmp_path, only=only), SimulatedLLM(), settings, prices)
    bge = await evaluate(
        options(tmp_path, only=only), SimulatedLLM(), settings, prices, fake_ollama()
    )

    assert (local[0].retrieval, bge[0].retrieval) == ("local-bow", "bge-m3")
    # Misma ejecución, otra búsqueda: no se reutiliza lo de la bolsa de palabras.
    assert bge[0].fingerprint != local[0].fingerprint
    # Umbral de producción (0,45), no el de la bolsa de palabras (0,15).
    retriever = await knowledge(settings, fake_ollama())
    found = await retriever.search(TenantId(1), "plazo para devolver un producto y reembolso")
    assert found.chunks
    assert all(c.similarity >= settings.rag_min_similarity for c in found.chunks)
    summary = write_report(options(tmp_path, only=only))
    assert summary.retrieval == "bge-m3"


async def test_ollama_down_stops_before_the_first_case(tmp_path: Path) -> None:
    prices = parse_pricing((REPO / "config" / "ai.php").read_text("utf-8"))
    settings = settings_for("simulated", "simulated")

    with pytest.raises(EvaluationSetupError, match="bge-m3"):
        await evaluate(options(tmp_path), SimulatedLLM(), settings, prices, fake_ollama(fail=True))

    assert not (tmp_path / "prueba.jsonl").exists()


def test_the_ollama_option_names_its_run() -> None:
    parsed = parse_args(["--provider", "anthropic", "--embeddings", "ollama"])

    assert (parsed.embeddings, parsed.ollama_url) == ("ollama", "http://ollama:11434")
    assert parsed.run.endswith("-bge")
    assert parse_args(["--embeddings", "ollama", "--run", "x"]).run == "x"


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

    # Otro umbral, recalculado sin llamar a la IA y sin pisar el informe original.
    original = (tmp_path / "a.md").read_text("utf-8")
    assert main([*args, "--run", "a", "--report-only", "--smoke", "--min-confidence", "0.5"]) == 0
    assert (tmp_path / "a-umbral-0.50.md").exists()
    assert (tmp_path / "a.md").read_text("utf-8") == original
    assert main(["--compare", "a", "a", "--min-confidence", "0.5", "--out", out]) in (0, 1)
    assert (tmp_path / "compare-a-vs-a-umbral-0.50.md").exists()


def test_a_new_threshold_is_only_recalculated_never_run() -> None:
    with pytest.raises(SystemExit):
        parse_args(["--provider", "anthropic", "--min-confidence", "0.8"])
    with pytest.raises(SystemExit):
        parse_args(["--report-only", "--min-confidence", "1.5"])
    assert parse_args(["--report-only", "--min-confidence", "0.8"]).min_confidence == 0.8


def test_each_step_can_use_its_own_model() -> None:
    parsed = parse_args(
        [
            "--provider",
            "anthropic",
            "--model",
            "claude-opus-5-5",
            "--classify-model",
            "claude-haiku-5-5",
            "--draft-model",
            "claude-sonnet-5-5",
        ]
    )

    assert parsed.models == "claude-haiku-5-5 + claude-sonnet-5-5"
    assert parsed.run == "anthropic-claude-haiku-5-5+claude-sonnet-5-5"
    settings = settings_for(
        "anthropic",
        parsed.model,
        classify_model=parsed.classify_model,
        draft_model=parsed.draft_model,
    )
    assert (settings.classify_model, settings.draft_model) == (
        "claude-haiku-5-5",
        "claude-sonnet-5-5",
    )
    # Con un solo modelo la ejecución se llama como siempre (la caché sigue valiendo).
    single = parse_args(["--provider", "anthropic", "--model", "claude-opus-5-5"])
    assert (single.models, single.run) == ("claude-opus-5-5", "anthropic-claude-opus-5-5")
    # La IA simulada no tiene modelos por paso.
    assert parse_args(["--classify-model", "claude-haiku-5-5"]).models == "simulated"
