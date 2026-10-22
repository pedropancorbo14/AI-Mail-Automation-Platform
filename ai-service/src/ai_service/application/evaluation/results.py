"""Resultado de evaluar un caso: lo que hizo el agente, en JSON.

Se guarda una línea por caso (`evals/runs/<ejecución>.jsonl`) y hace de caché:
un caso con la misma huella no se vuelve a ejecutar, así repetir una ejecución
que se cortó no vuelve a pagar lo ya hecho. La huella es lo que cambia lo que
hace el agente: el email (empresa, remitente, asunto, cuerpo), proveedor,
modelo, versiones de los prompts y la versión de las reglas de decisión. Las
expectativas NO forman parte: corregir una se puntúa con `--report-only` sin
volver a llamar a la IA.
"""

import hashlib
import json
from dataclasses import asdict, dataclass, field
from typing import Any, Literal

from ai_service.application.dto import AgentOutcome, LLMCall
from ai_service.application.evaluation.dataset import Case


def fingerprint(
    case: Case, *, provider: str, model: str, prompts: tuple[str, ...], policy: str
) -> str:
    payload = json.dumps(
        {
            "email": {
                "tenant": case.tenant.value,
                "from": case.sender,
                "subject": case.subject,
                "body": case.body,
            },
            "provider": provider,
            "model": model,
            "prompts": list(prompts),
            "policy": policy,
        },
        sort_keys=True,
        ensure_ascii=False,
    )
    return hashlib.sha256(payload.encode()).hexdigest()[:16]


@dataclass(frozen=True)
class CaseRun:
    case_id: str
    fingerprint: str
    provider: str
    model: str
    status: Literal["ok", "error"]
    error: str | None = None
    category: str | None = None
    classification_confidence: float | None = None
    language: str | None = None
    injection_suspected: bool = False
    decision: str | None = None
    reasons: tuple[str, ...] = ()
    reply: str | None = None
    reply_confidence: float | None = None
    pending_actions: tuple[str, ...] = ()
    sources: tuple[tuple[str, str], ...] = ()
    specialists: tuple[str, ...] = ()
    tool_calls: tuple[dict[str, Any], ...] = ()
    calls: tuple[dict[str, Any], ...] = ()
    cost_usd: float | None = None
    duration_ms: int = 0
    extra: dict[str, Any] = field(default_factory=dict)

    @classmethod
    def from_outcome(
        cls,
        *,
        case_id: str,
        fingerprint: str,
        provider: str,
        model: str,
        outcome: AgentOutcome,
        cost: float | None,
    ) -> "CaseRun":
        c, draft = outcome.classification, outcome.draft
        return cls(
            case_id=case_id,
            fingerprint=fingerprint,
            provider=provider,
            model=model,
            status="ok",
            category=c.category.value,
            classification_confidence=c.confidence.value,
            language=c.language,
            injection_suspected=c.injection_suspected,
            decision=outcome.outcome.decision.value,
            reasons=outcome.outcome.reasons,
            reply=draft.body if draft else None,
            reply_confidence=draft.confidence.value if draft else None,
            pending_actions=draft.pending_actions if draft else (),
            sources=tuple((s.type.value, s.reference) for s in draft.sources) if draft else (),
            specialists=tuple(s.value for s in outcome.specialists),
            tool_calls=tuple(
                {
                    "tool": t.tool,
                    "origin": t.origin,
                    "status": t.status,
                    "arguments": t.arguments,
                    "result": t.result,
                    "error": t.error,
                }
                for t in outcome.tool_calls
            ),
            calls=tuple(_call(c) for c in outcome.calls),
            cost_usd=cost,
            duration_ms=outcome.duration_ms,
        )

    @classmethod
    def failed(
        cls,
        *,
        case_id: str,
        fingerprint: str,
        provider: str,
        model: str,
        error: str,
        calls: tuple[LLMCall, ...],
        cost: float | None,
    ) -> "CaseRun":
        return cls(
            case_id=case_id,
            fingerprint=fingerprint,
            provider=provider,
            model=model,
            status="error",
            error=error,
            calls=tuple(_call(c) for c in calls),
            cost_usd=cost,
        )

    def to_json(self) -> str:
        return json.dumps(asdict(self), ensure_ascii=False, default=str)

    @classmethod
    def from_json(cls, line: str) -> "CaseRun":
        data = json.loads(line)
        for key in ("reasons", "pending_actions", "specialists", "tool_calls", "calls"):
            data[key] = tuple(data.get(key) or ())
        data["sources"] = tuple(tuple(s) for s in data.get("sources") or ())
        return cls(**data)


def _call(call: LLMCall) -> dict[str, Any]:
    return {
        "purpose": call.purpose,
        "provider": call.provider,
        "model": call.model,
        "status": call.status,
        "input_tokens": call.input_tokens,
        "output_tokens": call.output_tokens,
        "cache_write_tokens": call.cache_write_tokens,
        "cache_read_tokens": call.cache_read_tokens,
        "latency_ms": call.latency_ms,
        "prompt": call.prompt,
    }
