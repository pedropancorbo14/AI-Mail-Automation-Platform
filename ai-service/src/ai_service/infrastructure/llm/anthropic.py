"""Adaptador de `LLMPort` para Claude, con langchain-anthropic.

- **Structured outputs nativos** (`method="json_schema"` → `output_config.format`):
  en Claude Opus 5.5 forzar `tool_choice` da 400, y el método por defecto de
  `with_structured_output` (function calling) lo fuerza.
- **`effort`** por operación (clasificar: low; redactar: medium). En Opus 5.5 el
  razonamiento no se puede desactivar: `effort` es el control.
- **Fallbacks de servidor** (`fallbacks: "default"` + beta): si los filtros de
  seguridad rechazan, Anthropic reintenta con otro modelo en la misma llamada.
  Cada modelo que intervino es una `LLMCall` (factura a su tarifa).
- Siempre se mira `stop_reason` antes de usar la respuesta (`refusal`,
  `max_tokens`).
- Los errores del SDK se traducen a `LLMError` y subclases: fuera de aquí no
  aparece ninguna excepción de Anthropic. Nunca la clave en un mensaje.
"""

import time
from collections.abc import Mapping, Sequence
from dataclasses import replace
from typing import Any

import anthropic
from langchain_anthropic import ChatAnthropic
from langchain_core.messages import AIMessage
from pydantic import SecretStr

from ai_service.application.dto import CallStatus, ClassificationResult, DraftResult, LLMCall
from ai_service.application.errors import (
    LLMConfigurationError,
    LLMError,
    LLMInvalidOutputError,
    LLMRefusalError,
    LLMUnavailableError,
)
from ai_service.config import Effort, Settings
from ai_service.domain.category import Category
from ai_service.domain.classification import Classification, Sentiment, Urgency
from ai_service.domain.confidence import Confidence
from ai_service.domain.draft import Draft
from ai_service.domain.email import Email
from ai_service.infrastructure.llm import prompts
from ai_service.infrastructure.llm.prompts import Prompt

PROVIDER = "anthropic"
FALLBACK_BETA = "server-side-fallback-2026-07-01"


class AnthropicLLM:
    """Implementa `LLMPort` (classify, draft) con Claude."""

    def __init__(
        self,
        classifier: ChatAnthropic,
        drafter: ChatAnthropic,
        *,
        model: str,
        max_body_chars: int,
    ) -> None:
        self._classifier = classifier.with_structured_output(
            prompts.CLASSIFICATION.output_schema, method="json_schema", include_raw=True
        )
        self._drafter = drafter.with_structured_output(
            prompts.DRAFT.output_schema, method="json_schema", include_raw=True
        )
        self._model = model
        self._max_body_chars = max_body_chars

    @classmethod
    def from_settings(cls, settings: Settings) -> "AnthropicLLM":
        if settings.anthropic_api_key is None or not settings.anthropic_api_key.get_secret_value():
            raise LLMConfigurationError(
                "Falta la clave de Anthropic: define AI_SERVICE_ANTHROPIC_API_KEY."
            )
        return cls(
            chat_model(settings, settings.classify_effort, settings.classify_max_tokens),
            chat_model(settings, settings.draft_effort, settings.draft_max_tokens),
            model=settings.anthropic_model,
            max_body_chars=settings.max_body_chars,
        )

    async def classify(self, email: Email) -> ClassificationResult:
        content = prompts.email_block(email, self._max_body_chars)
        data, calls = await self._invoke(self._classifier, prompts.CLASSIFICATION, content)
        try:
            classification = _classification(data)
        except (KeyError, ValueError, TypeError) as error:
            raise LLMInvalidOutputError(
                f"La clasificación no tiene el formato esperado: {error}", calls
            ) from error
        return ClassificationResult(classification, calls)

    async def draft(self, email: Email, classification: Classification) -> DraftResult:
        content = prompts.draft_context(email, classification, self._max_body_chars)
        data, calls = await self._invoke(self._drafter, prompts.DRAFT, content)
        try:
            draft = _draft(data)
        except (KeyError, ValueError, TypeError) as error:
            raise LLMInvalidOutputError(
                f"El borrador no tiene el formato esperado: {error}", calls
            ) from error
        return DraftResult(draft, calls)

    async def _invoke(
        self, runnable: Any, prompt: Prompt, content: str
    ) -> tuple[Mapping[str, Any], tuple[LLMCall, ...]]:
        purpose = f"ai_service.{prompt.key.removeprefix('email_')}"
        started = time.perf_counter()
        try:
            result = await runnable.ainvoke([("system", prompt.system), ("user", content)])
        except anthropic.APIError as error:
            latency = _ms(started)
            failed = (self._failed_call(purpose, prompt, latency, _error_text(error)),)
            raise _translate(error, failed) from error

        latency = _ms(started)
        raw: AIMessage = result["raw"]
        calls = _calls(raw, purpose, prompt, self._model, latency)
        stop_reason = raw.response_metadata.get("stop_reason")

        if stop_reason == "refusal":
            details = raw.response_metadata.get("stop_details") or {}
            category = details.get("category") if isinstance(details, Mapping) else None
            suffix = f" (categoría: {category})" if category else ""
            refused = tuple(_with_status(c, "refused") for c in calls)
            raise LLMRefusalError(f"El modelo se negó a procesar el email{suffix}.", refused)
        if stop_reason == "max_tokens":
            raise LLMInvalidOutputError(
                "La respuesta se cortó por max_tokens: súbelo en la configuración.", calls
            )
        parsed = result.get("parsed")
        if result.get("parsing_error") is not None or not isinstance(parsed, Mapping):
            raise LLMInvalidOutputError("La respuesta del modelo no es el JSON esperado.", calls)
        return parsed, calls

    def _failed_call(self, purpose: str, prompt: Prompt, latency: int, error: str) -> LLMCall:
        return LLMCall(
            purpose=purpose,
            provider=PROVIDER,
            requested_model=self._model,
            model=self._model,
            status="failed",
            latency_ms=latency,
            error=error,
            prompt=prompt.ref,
        )


def chat_model(settings: Settings, effort: Effort, max_tokens: int) -> ChatAnthropic:
    """ChatAnthropic configurado para una operación (cada una con su effort)."""
    key = settings.anthropic_api_key or SecretStr("")
    kwargs: dict[str, Any] = {}
    betas: list[str] = []
    if settings.anthropic_fallbacks:
        kwargs["fallbacks"] = "default"
        betas.append(FALLBACK_BETA)
    return ChatAnthropic(  # type: ignore[call-arg]
        model=settings.anthropic_model,
        api_key=key,
        max_tokens=max_tokens,
        reasoning_effort=effort,
        default_request_timeout=settings.llm_timeout_seconds,
        max_retries=settings.llm_max_retries,
        betas=betas or None,
        model_kwargs=kwargs,
    )


def _calls(
    raw: AIMessage, purpose: str, prompt: Prompt, requested: str, latency: int
) -> tuple[LLMCall, ...]:
    """Una llamada por modelo que intervino (con fallback, `usage.iterations`)."""
    meta = raw.response_metadata
    usage = meta.get("usage") or {}
    request_id = meta.get("id")
    stop_reason = meta.get("stop_reason")
    served_by = str(meta.get("model") or meta.get("model_name") or requested)
    iterations = [
        it
        for it in (usage.get("iterations") or [])
        if isinstance(it, Mapping) and it.get("type") in ("message", "fallback_message")
    ]
    if not iterations:
        iterations = [dict(usage, model=served_by)]

    calls = []
    for index, it in enumerate(iterations):
        last = index == len(iterations) - 1
        calls.append(
            LLMCall(
                purpose=purpose,
                provider=PROVIDER,
                requested_model=requested,
                model=str(it.get("model") or (served_by if last else requested)),
                # Los intentos anteriores al último son los que el modelo rechazó.
                status="success" if last else "refused",
                input_tokens=int(it.get("input_tokens") or 0),
                output_tokens=int(it.get("output_tokens") or 0),
                cache_write_tokens=int(it.get("cache_creation_input_tokens") or 0),
                cache_read_tokens=int(it.get("cache_read_input_tokens") or 0),
                latency_ms=latency if last else 0,
                stop_reason=str(stop_reason) if last and stop_reason else None,
                provider_request_id=str(request_id) if request_id else None,
                prompt=prompt.ref,
            )
        )
    return tuple(calls)


def _with_status(call: LLMCall, status: CallStatus) -> LLMCall:
    return replace(call, status=status)


def _translate(error: anthropic.APIError, calls: tuple[LLMCall, ...]) -> LLMError:
    if isinstance(error, anthropic.APIConnectionError):  # incluye APITimeoutError
        return LLMUnavailableError("No se pudo conectar con Anthropic (red o timeout).", calls)
    if isinstance(error, anthropic.RateLimitError):
        return LLMUnavailableError("Anthropic limita las peticiones (429): se reintentará.", calls)
    if isinstance(error, anthropic.AuthenticationError | anthropic.PermissionDeniedError):
        return LLMConfigurationError(
            "Anthropic rechazó la clave: revisa AI_SERVICE_ANTHROPIC_API_KEY.", calls
        )
    if isinstance(error, anthropic.APIStatusError):
        if error.status_code >= 500:
            return LLMUnavailableError(
                f"Anthropic no está disponible ({error.status_code}): se reintentará.", calls
            )
        return LLMConfigurationError(
            f"Anthropic rechazó la petición ({error.status_code}): {_error_text(error)}", calls
        )
    return LLMUnavailableError("Error al llamar a Anthropic.", calls)


def _error_text(error: anthropic.APIError) -> str:
    body = error.body
    if isinstance(body, Mapping):
        inner = body.get("error")
        if isinstance(inner, Mapping):
            kind, message = inner.get("type"), inner.get("message")
            if kind or message:
                return f"{kind}: {message}"[:300]
    return type(error).__name__


def _ms(started: float) -> int:
    return int((time.perf_counter() - started) * 1000)


def _strings(value: Any) -> tuple[str, ...]:
    if not isinstance(value, Sequence) or isinstance(value, str):
        return ()
    return tuple(str(v).strip() for v in value if str(v).strip())


def _classification(data: Mapping[str, Any]) -> Classification:
    entities = data.get("entities") or {}
    reason = data.get("needs_human_reason")
    name = entities.get("customer_name")
    return Classification(
        category=Category(data["intent"]),
        confidence=Confidence.clamped(float(data["confidence"])),
        language=str(data["language"]).strip().lower()[:20] or "und",
        summary=str(data["summary"]).strip(),
        sentiment=Sentiment(data["sentiment"]),
        urgency=Urgency(data["urgency"]),
        injection_suspected=bool(data["injection_suspected"]),
        needs_human=bool(data["needs_human"]),
        needs_human_reason=str(reason).strip() if reason else None,
        order_numbers=_strings(entities.get("order_numbers")),
        invoice_numbers=_strings(entities.get("invoice_numbers")),
        customer_name=str(name).strip() if name else None,
    )


def _draft(data: Mapping[str, Any]) -> Draft:
    body = str(data["reply_body"])
    if not body.strip():
        raise ValueError("el borrador está vacío")
    reason = data.get("needs_human_reason")
    return Draft(
        body=body,
        language=str(data["reply_language"]).strip().lower()[:20] or "und",
        confidence=Confidence.clamped(float(data["confidence"])),
        needs_human=bool(data["needs_human"]),
        needs_human_reason=str(reason).strip() if reason else None,
        pending_actions=_strings(data.get("pending_actions")),
    )
