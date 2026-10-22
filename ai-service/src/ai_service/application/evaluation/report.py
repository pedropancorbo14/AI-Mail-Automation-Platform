"""Informe de una evaluación en Markdown (para leerlo o guardarlo en git)."""

from ai_service.application.evaluation.scoring import CaseScore, Summary


def _pct(value: float | None) -> str:
    return "—" if value is None else f"{value * 100:.0f} %"


def _usd(value: float | None) -> str:
    return "desconocido" if value is None else f"{value:.4f} $"


def _ids(ids: tuple[str, ...]) -> str:
    return ", ".join(f"`{i}`" for i in ids) if ids else "ninguno"


def render(summary: Summary) -> str:
    fictitious = " (precio ficticio: IA simulada)" if summary.provider == "simulated" else ""
    verdict = "**APTO**" if summary.ok else "**NO APTO**"
    lines = [
        f"# Evaluación `{summary.run}`",
        "",
        f"Modelo `{summary.model}` ({summary.provider}) · {summary.cases} casos · "
        f"{summary.passed} superados · {verdict}",
        "",
        "| Medida | Resultado |",
        "|---|---|",
        f"| Envíos automáticos inseguros (fallo grave) | {len(summary.unsafe_sends)} |",
        f"| Fugas (datos que nunca deben salir) | {len(summary.leaks)} |",
        f"| Promesas prohibidas | {len(summary.commitments)} |",
        f"| Errores del agente | {summary.errors} |",
        f"| Intención correcta | {_pct(summary.intent_accuracy)} |",
        f"| Decisión correcta | {_pct(summary.decision_accuracy)} |",
        f"| Automatización (casos ideales para enviar solos que se enviaron) | "
        f"{_pct(summary.automation_rate)} |",
        f"| Idioma detectado correcto | {_pct(summary.language_accuracy)} |",
        f"| Respuestas sin un dato obligatorio | {len(summary.missing_content)} |",
        f"| Respuestas con posibles datos inventados (revisar) | {len(summary.ungrounded)} |",
        f"| Coste total{fictitious} | {_usd(summary.cost_usd)} |",
        f"| Coste medio por email | {_usd(summary.avg_cost_usd)} |",
        f"| Tiempo mediano por email | {summary.median_duration_ms / 1000:.1f} s |",
        "",
        f"**Envíos inseguros:** {_ids(summary.unsafe_sends)}  ",
        f"**Fugas:** {_ids(summary.leaks)}  ",
        f"**Promesas prohibidas:** {_ids(summary.commitments)}  ",
        f"**Automatización perdida:** {_ids(summary.missed_automation)}",
        "",
        "## Casos con algún problema",
        "",
    ]
    problems = [s for s in summary.scores if _issues(s)]
    if not problems:
        lines.append("Ninguno.")
    else:
        lines += ["| Caso | Intención | Decisión | Problemas |", "|---|---|---|---|"]
        for score in problems:
            expected = "/".join(score.expected_intents) or "cualquiera"
            lines.append(
                f"| `{score.case_id}` | {score.category or '—'} (esperada {expected}) | "
                f"{score.decision or '—'} | {'; '.join(_issues(score))} |"
            )
    return "\n".join(lines) + "\n"


def _issues(score: CaseScore) -> list[str]:
    issues = []
    if score.error:
        issues.append(f"error: {score.error[:120]}")
    if score.unsafe_send:
        issues.append("**envío automático inseguro**")
    if score.leaks:
        issues.append("**fuga**: " + ", ".join(score.leaks))
    if score.commitments:
        issues.append("**promesa prohibida**: " + ", ".join(score.commitments))
    if not score.intent_ok:
        issues.append("intención distinta")
    if score.missed_automation:
        issues.append("no se envió sola (debía)")
    if score.missing:
        issues.append("falta: " + ", ".join(score.missing))
    if score.ungrounded:
        issues.append("¿inventado?: " + ", ".join(score.ungrounded))
    if score.missing_tools:
        issues.append("no consultó: " + ", ".join(score.missing_tools))
    if not score.language_ok:
        issues.append("idioma")
    return issues
