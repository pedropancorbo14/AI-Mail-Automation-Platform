"""Comparar dos ejecuciones de la evaluación caso a caso.

Para cada cambio de prompt, modelo o regla: qué mejoró, qué empeoró y, sobre
todo, si aparece algún fallo de seguridad nuevo (envío inseguro, fuga, promesa
prohibida o error) que la ejecución de referencia no tenía. Solo se comparan
los casos que están en las dos.
"""

from dataclasses import dataclass

from ai_service.application.evaluation.scoring import CaseScore, Summary


@dataclass(frozen=True)
class Change:
    case_id: str
    before: CaseScore
    after: CaseScore

    @property
    def decision_changed(self) -> bool:
        return self.before.decision != self.after.decision

    @property
    def intent_changed(self) -> bool:
        return self.before.category != self.after.category


@dataclass(frozen=True)
class Comparison:
    before: Summary
    after: Summary
    common: tuple[str, ...]
    new_safety_failures: tuple[str, ...]
    fixed_safety_failures: tuple[str, ...]
    regressions: tuple[str, ...]  # superado → no superado
    improvements: tuple[str, ...]  # no superado → superado
    changes: tuple[Change, ...]  # decisión o intención distintas

    @property
    def ok(self) -> bool:
        """Sin fallos de seguridad nuevos respecto a la referencia."""
        return not self.new_safety_failures


def _safety(score: CaseScore) -> tuple[str, ...]:
    problems = []
    if score.error is not None:
        problems.append("error")
    if score.unsafe_send:
        problems.append("envío inseguro")
    if score.leaks:
        problems.append("fuga")
    if score.commitments:
        problems.append("promesa prohibida")
    return tuple(problems)


def compare(before: Summary, after: Summary) -> Comparison:
    a = {s.case_id: s for s in before.scores}
    b = {s.case_id: s for s in after.scores}
    common = tuple(case_id for case_id in a if case_id in b)
    pairs = [Change(case_id, a[case_id], b[case_id]) for case_id in common]
    return Comparison(
        before=before,
        after=after,
        common=common,
        new_safety_failures=tuple(
            p.case_id for p in pairs if set(_safety(p.after)) - set(_safety(p.before))
        ),
        fixed_safety_failures=tuple(
            p.case_id for p in pairs if set(_safety(p.before)) - set(_safety(p.after))
        ),
        regressions=tuple(p.case_id for p in pairs if p.before.passed and not p.after.passed),
        improvements=tuple(p.case_id for p in pairs if not p.before.passed and p.after.passed),
        changes=tuple(p for p in pairs if p.decision_changed or p.intent_changed),
    )


def _pct(value: float | None) -> str:
    return "—" if value is None else f"{value * 100:.0f} %"


def _usd(value: float | None) -> str:
    return "—" if value is None else f"{value:.4f} $"


def _ids(ids: tuple[str, ...]) -> str:
    return ", ".join(f"`{i}`" for i in ids) if ids else "ninguno"


def render_comparison(comparison: Comparison) -> str:
    a, b = comparison.before, comparison.after
    scores_a = {s.case_id: s for s in a.scores}
    scores_b = {s.case_id: s for s in b.scores}
    common = comparison.common

    def count(scores: dict[str, CaseScore], test: str) -> int:
        return sum(test in _safety(scores[c]) for c in common)

    def passed(scores: dict[str, CaseScore]) -> int:
        return sum(scores[c].passed for c in common)

    verdict = (
        "**Sin fallos de seguridad nuevos**" if comparison.ok else "**FALLOS DE SEGURIDAD NUEVOS**"
    )
    rows = [
        (
            "Envíos automáticos inseguros",
            count(scores_a, "envío inseguro"),
            count(scores_b, "envío inseguro"),
        ),
        ("Fugas", count(scores_a, "fuga"), count(scores_b, "fuga")),
        (
            "Promesas prohibidas",
            count(scores_a, "promesa prohibida"),
            count(scores_b, "promesa prohibida"),
        ),
        ("Errores del agente", count(scores_a, "error"), count(scores_b, "error")),
        ("Casos superados", passed(scores_a), passed(scores_b)),
    ]
    lines = [
        f"# Comparación `{a.run}` → `{b.run}`",
        "",
        f"`{a.model}` → `{b.model}` · {len(common)} casos en las dos · {verdict}",
        "",
        "Los totales de la tabla son solo de los casos comunes; los porcentajes y el coste,"
        " de cada ejecución completa.",
        "",
        f"| Medida | `{a.run}` | `{b.run}` |",
        "|---|---|---|",
        *(f"| {name} | {x} | {y} |" for name, x, y in rows),
        f"| Intención correcta | {_pct(a.intent_accuracy)} | {_pct(b.intent_accuracy)} |",
        f"| Decisión correcta | {_pct(a.decision_accuracy)} | {_pct(b.decision_accuracy)} |",
        f"| Automatización | {_pct(a.automation_rate)} | {_pct(b.automation_rate)} |",
        f"| Coste medio por email | {_usd(a.avg_cost_usd)} | {_usd(b.avg_cost_usd)} |",
        f"| Tiempo mediano por email | {a.median_duration_ms / 1000:.1f} s"
        f" | {b.median_duration_ms / 1000:.1f} s |",
        "",
        f"**Fallos de seguridad nuevos:** {_ids(comparison.new_safety_failures)}  ",
        f"**Fallos de seguridad corregidos:** {_ids(comparison.fixed_safety_failures)}  ",
        f"**Empeoran (superado → no superado):** {_ids(comparison.regressions)}  ",
        f"**Mejoran (no superado → superado):** {_ids(comparison.improvements)}",
        "",
        "## Casos con decisión o intención distinta",
        "",
    ]
    if not comparison.changes:
        lines.append("Ninguno.")
    else:
        lines += [
            f"| Caso | Intención | Decisión | `{a.run}` | `{b.run}` |",
            "|---|---|---|---|---|",
        ]
        for change in comparison.changes:
            x, y = change.before, change.after
            intent = (
                f"{x.category} → {y.category}" if change.intent_changed else (y.category or "—")
            )
            decision = (
                f"{x.decision} → {y.decision}" if change.decision_changed else (y.decision or "—")
            )
            lines.append(
                f"| `{change.case_id}` | {intent} | {decision}"
                f" | {'superado' if x.passed else 'no superado'}"
                f" | {'superado' if y.passed else 'no superado'} |"
            )
    return "\n".join(lines) + "\n"
