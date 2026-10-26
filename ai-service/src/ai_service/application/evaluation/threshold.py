"""Recalcular la decisión con otro umbral de confianza de la respuesta (15.9).

La decisión es determinista a partir de sus motivos (`domain/policy.py`), y la
confianza de cada respuesta está guardada: se puede probar otro umbral sobre
una ejecución ya hecha sin volver a llamar a la IA. Solo cambia el motivo de
la confianza de la respuesta; los demás (acciones pendientes, datos no
encontrados, promesas, la clasificación...) se quedan como estaban, y un caso
escalado sigue escalado.
"""

from dataclasses import replace

from ai_service.application.evaluation.results import CaseRun

CONFIDENCE_REASON = "La confianza en la respuesta ("


def _es(value: float) -> str:
    return f"{value:.2f}".replace(".", ",")


def recalculate(run: CaseRun, min_confidence: float) -> CaseRun:
    """El mismo caso decidido con `min_confidence` como umbral de la respuesta."""
    if run.status != "ok" or run.reply_confidence is None:
        return run
    reasons = [r for r in run.reasons if not r.startswith(CONFIDENCE_REASON)]
    if run.reply_confidence < min_confidence:
        reasons.append(
            f"{CONFIDENCE_REASON}{_es(run.reply_confidence)}) es menor que el mínimo "
            f"({_es(min_confidence)})."
        )
    if run.decision == "escalate":
        decision = "escalate"
    else:
        decision = "human_review" if reasons else "auto_send"
    return replace(run, decision=decision, reasons=tuple(reasons))
