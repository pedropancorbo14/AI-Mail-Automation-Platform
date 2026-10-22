"""`ai-service-eval`: evalúa el agente con el conjunto de casos (Fase 15).

Monta el agente de producción (grafo, prompts, política de decisión) con:

- las herramientas del **servidor MCP real**, arrancado en este proceso con
  los datos de demostración (Empresa Demo = empresa 1, Clínica Demo =
  empresa 2) y una fecha fija (2026-10-22), para que "la próxima cita" sea
  siempre la misma;
- la documentación de ejemplo de Empresa Demo (`database/seeders/knowledge`)
  en un índice local en memoria (`infrastructure/knowledge/local.py`);
- la IA elegida: `--provider simulated` (sin coste, para comprobar el
  ejecutor) o `anthropic` (Claude real: cuesta dinero).

Protecciones del gasto: `--max-cost-usd` (para antes del siguiente caso al
alcanzarlo), `--limit` y `--only` (probar con pocos), y la **caché**: cada
caso ya evaluado con la misma huella se reutiliza de `evals/runs/<run>.jsonl`
(`--fresh` para repetirlo).

    uv run ai-service-eval --provider simulated --run sim
    uv run ai-service-eval --provider anthropic --run opus --limit 3 --max-cost-usd 0.5
"""

import argparse
import asyncio
import contextlib
import json
import logging
import os
import socket
import sys
import time
from collections.abc import AsyncIterator, Iterable
from dataclasses import dataclass
from datetime import UTC, datetime
from pathlib import Path

import uvicorn
from pydantic import SecretStr

from ai_service.agents.email_graph import LangGraphEmailAgent
from ai_service.application.errors import AgentError
from ai_service.application.evaluation.costs import Price, cost_usd, parse_pricing
from ai_service.application.evaluation.dataset import Case, Tenant, load_dataset
from ai_service.application.evaluation.report import render
from ai_service.application.evaluation.results import CaseRun, fingerprint
from ai_service.application.evaluation.scoring import Summary, summarize
from ai_service.application.ports import LLMPort
from ai_service.application.services.chunking import TextChunker
from ai_service.application.services.knowledge import KnowledgeRetriever, RetrievalOptions
from ai_service.application.services.tool_catalog import SEARCH_TOOL
from ai_service.config import Environment, Settings
from ai_service.domain.confidence import Confidence
from ai_service.domain.email import Email, EmailAddress, TenantId
from ai_service.domain.policy import DecisionPolicy
from ai_service.entrypoints.container import build_llm
from ai_service.infrastructure.documents.extraction import DocumentTextExtractor
from ai_service.infrastructure.knowledge.local import LocalEmbeddings, MemoryVectorStore
from ai_service.infrastructure.llm import prompts
from ai_service.infrastructure.tools.mcp_client import McpToolClient
from ai_service.mcp_server.config import McpSettings
from ai_service.mcp_server.integrations.demo import DemoIntegrations
from ai_service.mcp_server.server import build_app
from ai_service.mcp_server.tools import BusinessTools

AI_SERVICE_DIR = Path(__file__).resolve().parents[3]
REPO = AI_SERVICE_DIR.parent
NOW = datetime(2026, 10, 22, 10, 0, tzinfo=UTC)
MCP_TOKEN = "eval-local-token"  # noqa: S105 - servidor MCP en este proceso, solo 127.0.0.1


@dataclass(frozen=True)
class Company:
    tenant_id: int
    name: str
    tools: frozenset[str]


COMPANIES = {
    Tenant.SHOP: Company(
        1,
        "Empresa Demo",
        frozenset(
            {
                SEARCH_TOOL,
                "get_customer",
                "get_order",
                "get_customer_orders",
                "get_invoice",
                "get_customer_invoices",
                "get_customer_tickets",
            }
        ),
    ),
    Tenant.CLINIC: Company(
        2, "Clínica Demo", frozenset({SEARCH_TOOL, "get_appointment", "get_customer_appointments"})
    ),
}


@dataclass(frozen=True)
class Options:
    provider: str
    model: str
    run: str
    dataset: Path
    out: Path
    only: set[str] | None
    limit: int | None
    max_cost_usd: float
    fresh: bool
    report_only: bool = False


def email_for(case: Case, index: int) -> Email:
    company = COMPANIES[case.tenant]
    return Email(
        email_id=index,
        tenant_id=TenantId(company.tenant_id),
        sender=EmailAddress(case.sender),
        subject=case.subject,
        body=case.body,
        received_at=NOW,
        tenant_name=company.name,
        enabled_tools=company.tools,
    )


async def knowledge(settings: Settings) -> KnowledgeRetriever:
    """La documentación de ejemplo de Empresa Demo, indexada en memoria."""
    embeddings, store = LocalEmbeddings(), MemoryVectorStore()
    chunker = TextChunker(settings.chunk_max_chars, settings.chunk_overlap_chars)
    extractor = DocumentTextExtractor()
    for document_id, path in enumerate(
        sorted((REPO / "database/seeders/knowledge").glob("*.md")), 1
    ):
        content = path.read_bytes()
        title = next(
            (line[2:].strip() for line in content.decode().splitlines() if line.startswith("# ")),
            path.stem,
        )
        chunks = chunker.chunk(extractor.extract(content, "text/markdown"))
        batch = await embeddings.embed_documents([c.text for c in chunks])
        store.add(
            TenantId(COMPANIES[Tenant.SHOP].tenant_id), document_id, title, chunks, batch.vectors
        )
    # Bolsa de palabras: los parecidos son más bajos que con bge-m3 (umbral 0,45).
    return KnowledgeRetriever(embeddings, store, RetrievalOptions(limit=5, min_similarity=0.15))


@contextlib.asynccontextmanager
async def mcp_server() -> AsyncIterator[str]:
    mcp = McpSettings()
    demo = DemoIntegrations.load(
        mcp.orders_file,
        mcp.tickets_file,
        {
            COMPANIES[Tenant.SHOP].tenant_id: "empresa-demo",
            COMPANIES[Tenant.CLINIC].tenant_id: "clinica-demo",
        },
        appointments_file=mcp.appointments_file,
    )
    app = build_app(BusinessTools(demo, clock=lambda: NOW), MCP_TOKEN)
    with socket.socket() as probe:
        probe.bind(("127.0.0.1", 0))
        port = probe.getsockname()[1]
    server = uvicorn.Server(uvicorn.Config(app, host="127.0.0.1", port=port, log_config=None))
    task = asyncio.create_task(server.serve())
    for _ in range(200):  # hasta 10 s
        if server.started:
            break
        await asyncio.sleep(0.05)
    else:
        raise RuntimeError("El servidor MCP de la evaluación no arrancó.")
    try:
        yield f"http://127.0.0.1:{port}"
    finally:
        server.should_exit = True
        await task


def load_previous(path: Path) -> dict[str, CaseRun]:
    """Lo ya evaluado en esta ejecución (la última línea de cada caso manda)."""
    if not path.exists():
        return {}
    runs = [
        CaseRun.from_json(line) for line in path.read_text("utf-8").splitlines() if line.strip()
    ]
    return {run.case_id: run for run in runs}


async def evaluate(
    options: Options, llm: LLMPort, settings: Settings, prices: dict[str, Price]
) -> list[CaseRun]:
    cases = load_dataset(options.dataset).select(options.only, options.limit)
    options.out.mkdir(parents=True, exist_ok=True)
    path = options.out / f"{options.run}.jsonl"
    previous = {} if options.fresh else load_previous(path)
    refs = (prompts.CLASSIFICATION.ref, prompts.DRAFT.ref)
    results: list[CaseRun] = []
    spent = 0.0

    retriever = await knowledge(settings)
    async with mcp_server() as url:
        agent = LangGraphEmailAgent(
            llm,
            DecisionPolicy(min_confidence=Confidence(settings.min_confidence)),
            retriever=retriever,
            tools=McpToolClient(url, MCP_TOKEN),
            max_tool_calls=settings.max_tool_calls,
            max_model_rounds=settings.max_model_tool_rounds,
            max_model_lookups=settings.max_model_tool_calls,
            max_tokens=settings.max_tokens_per_email,
            max_steps=settings.max_graph_steps,
            timeout_seconds=settings.agent_timeout_seconds,
        )
        with path.open("a", encoding="utf-8") as out:
            for index, case in enumerate(cases, 1):
                key = fingerprint(
                    case,
                    provider=options.provider,
                    model=options.model,
                    prompts=refs,
                    min_confidence=settings.min_confidence,
                )
                cached = previous.get(case.id)
                if cached is not None and cached.fingerprint == key and cached.status == "ok":
                    results.append(cached)
                    _progress(index, len(cases), cached, "caché")
                    continue
                if spent >= options.max_cost_usd:
                    print(
                        f"Límite de gasto alcanzado ({spent:.4f} $ de {options.max_cost_usd} $): "
                        f"quedan {len(cases) - index + 1} casos sin evaluar.",
                        file=sys.stderr,
                    )
                    break
                run = await _run_case(agent, case, index, key, options, prices)
                spent += run.cost_usd or 0.0
                out.write(run.to_json() + "\n")
                out.flush()
                results.append(run)
                _progress(index, len(cases), run, f"{(run.cost_usd or 0):.4f} $")
    return results


async def _run_case(
    agent: LangGraphEmailAgent,
    case: Case,
    index: int,
    key: str,
    options: Options,
    prices: dict[str, Price],
) -> CaseRun:
    base = {
        "case_id": case.id,
        "fingerprint": key,
        "provider": options.provider,
        "model": options.model,
    }
    try:
        outcome = await agent.run(email_for(case, index))
    except AgentError as error:
        return CaseRun.failed(
            **base, error=str(error), calls=error.calls, cost=cost_usd(error.calls, prices)
        )
    except Exception as error:  # el ejecutor no se para por un caso
        return CaseRun.failed(**base, error=f"{type(error).__name__}: {error}", calls=(), cost=0.0)
    return CaseRun.from_outcome(**base, outcome=outcome, cost=cost_usd(outcome.calls, prices))


def _progress(index: int, total: int, run: CaseRun, cost: str) -> None:
    what = run.decision if run.status == "ok" else f"ERROR {run.error}"
    print(f"[{index:>2}/{total}] {run.case_id:<32} {run.category or '-':<18} {what:<14} {cost}")


def settings_for(provider: str, model: str) -> Settings:
    # La clave puede venir como AI_SERVICE_ANTHROPIC_API_KEY o como ANTHROPIC_API_KEY.
    key = os.environ.get("AI_SERVICE_ANTHROPIC_API_KEY") or os.environ.get("ANTHROPIC_API_KEY")
    return Settings(
        environment=Environment.LOCAL,
        llm_provider=provider,  # type: ignore[arg-type]
        anthropic_model=model,
        anthropic_api_key=SecretStr(key) if key else None,
    )


def parse_args(argv: Iterable[str] | None = None) -> Options:
    parser = argparse.ArgumentParser(prog="ai-service-eval", description=__doc__.splitlines()[0])
    parser.add_argument("--provider", choices=["simulated", "anthropic"], default="simulated")
    parser.add_argument(
        "--model", default=None, help="Modelo de Claude (por defecto, el de la configuración)."
    )
    parser.add_argument(
        "--run", default=None, help="Nombre de la ejecución (fichero en evals/runs/)."
    )
    parser.add_argument("--dataset", type=Path, default=AI_SERVICE_DIR / "evals" / "dataset.json")
    parser.add_argument("--out", type=Path, default=AI_SERVICE_DIR / "evals" / "runs")
    parser.add_argument("--only", default=None, help="Ids de caso separados por comas.")
    parser.add_argument("--limit", type=int, default=None)
    parser.add_argument("--max-cost-usd", type=float, default=3.0)
    parser.add_argument("--fresh", action="store_true", help="No reutilizar resultados guardados.")
    parser.add_argument(
        "--report-only",
        action="store_true",
        help="Solo puntuar lo ya guardado (sin llamar a la IA).",
    )
    args = parser.parse_args(list(argv) if argv is not None else None)
    model = (
        "simulated" if args.provider == "simulated" else (args.model or Settings().anthropic_model)
    )
    return Options(
        provider=args.provider,
        model=model,
        run=args.run or f"{args.provider}-{model}",
        dataset=args.dataset,
        out=args.out,
        only=set(args.only.split(",")) if args.only else None,
        limit=args.limit,
        max_cost_usd=args.max_cost_usd,
        fresh=args.fresh,
        report_only=args.report_only,
    )


def write_report(options: Options) -> Summary:
    """Puntúa lo guardado de la ejecución y escribe `<run>.md` y `<run>.summary.json`."""
    cases = load_dataset(options.dataset).select(options.only, options.limit)
    runs = load_previous(options.out / f"{options.run}.jsonl")
    summary = summarize(options.run, cases, runs)
    (options.out / f"{options.run}.md").write_text(render(summary), "utf-8")
    (options.out / f"{options.run}.summary.json").write_text(
        json.dumps(summary.to_dict(), ensure_ascii=False, indent=2, default=list), "utf-8"
    )
    return summary


def main(argv: Iterable[str] | None = None) -> int:
    options = parse_args(argv)
    # Solo el progreso: sin los logs de cada petición al servidor MCP.
    for name in ("uvicorn", "uvicorn.error", "httpx", "httpx2", "mcp"):
        logging.getLogger(name).setLevel(logging.WARNING)
    if not options.report_only:
        settings = settings_for(options.provider, options.model)
        if options.provider == "anthropic" and settings.anthropic_api_key is None:
            print("Falta ANTHROPIC_API_KEY (o AI_SERVICE_ANTHROPIC_API_KEY).", file=sys.stderr)
            return 2
        prices = parse_pricing((REPO / "config" / "ai.php").read_text("utf-8"))
        started = time.perf_counter()
        results = asyncio.run(evaluate(options, build_llm(settings), settings, prices))
        spent = sum(r.cost_usd or 0.0 for r in results)
        print(
            f"\n{len(results)} casos en {time.perf_counter() - started:.1f} s · coste {spent:.4f} $"
            + (" (ficticio: IA simulada)" if options.provider == "simulated" else "")
        )
    summary = write_report(options)
    print("\n" + render(summary).split("\n## ")[0])
    print(f"Informe completo: {options.out / (options.run + '.md')}")
    return 0 if summary.ok else 1
