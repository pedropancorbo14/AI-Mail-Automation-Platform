"""Traducción entre el contrato de eventos y el dominio."""

import uuid

from ai_service.application.contracts.events import (
    ClassificationPayload,
    EmailProcessed,
    EmailProcessingFailed,
    EmailReceived,
    EmailReviewResumed,
    LLMCallUsage,
    Mode,
    PendingActionPayload,
    SourcePayload,
    ToolCallPayload,
    Usage,
)
from ai_service.application.dto import AgentOutcome, LLMCall
from ai_service.application.errors import AgentError
from ai_service.application.services.tool_catalog import DEFAULT_ENABLED_TOOLS
from ai_service.domain.email import Email, EmailAddress, TenantId


def email_from_event(event: EmailReceived) -> Email:
    return Email(
        email_id=event.email_id,
        tenant_id=TenantId(event.tenant_id),
        sender=EmailAddress(event.sender),
        sender_name=event.from_name,
        subject=event.subject,
        body=event.body,
        received_at=event.received_at,
        body_truncated=event.body_truncated,
        attachment_names=tuple(event.attachment_names),
        tenant_name=event.tenant_name,
        review_instruction=(event.review_instruction or "").strip() or None,
        enabled_tools=(
            DEFAULT_ENABLED_TOOLS if event.enabled_tools is None else frozenset(event.enabled_tools)
        ),
    )


def usage_from_calls(calls: tuple[LLMCall, ...]) -> Usage:
    return Usage(
        calls=[
            LLMCallUsage(
                purpose=c.purpose,
                provider=c.provider,
                requested_model=c.requested_model,
                model=c.model,
                status=c.status,
                input_tokens=c.input_tokens,
                output_tokens=c.output_tokens,
                cache_write_tokens=c.cache_write_tokens,
                cache_read_tokens=c.cache_read_tokens,
                latency_ms=c.latency_ms,
                stop_reason=c.stop_reason,
                provider_request_id=c.provider_request_id,
                error=c.error[:500] if c.error else None,
                prompt=c.prompt,
            )
            for c in calls
        ]
    )


Cause = EmailReceived | EmailReviewResumed


def thread_id_for(received: EmailReceived) -> str:
    """Hilo del grafo por si queda en pausa: único por petición de Laravel."""
    return f"t{received.tenant_id}-e{received.email_id}-{received.event_id}"


def mode_of(cause: Cause) -> Mode:
    """El modo del email recibido (una reanudación siempre es de un email real)."""
    return cause.mode if isinstance(cause, EmailReceived) else "live"


def processed_event(cause: Cause, result: AgentOutcome) -> EmailProcessed:
    """`causation_id` = el evento que lo provocó (el email recibido o la reanudación)."""
    c = result.classification
    draft = result.draft
    pending = result.pending_action
    return EmailProcessed(
        trace_id=cause.trace_id,
        tenant_id=cause.tenant_id,
        email_id=cause.email_id,
        causation_id=cause.event_id,
        category=c.category,
        draft=draft.body if draft else "",
        confidence=draft.confidence.value if draft else 0.0,
        decision=result.outcome.decision.value,
        tools_used=list(result.tools_used),
        language=(draft.language if draft else None) or c.language or None,
        reasons=[r[:500] for r in result.outcome.reasons[:20]],
        model=result.model,
        classification=ClassificationPayload(
            confidence=c.confidence.value,
            summary=c.summary[:2000],
            sentiment=c.sentiment.value,
            urgency=c.urgency.value,
            injection_suspected=c.injection_suspected,
            needs_human=c.needs_human,
            needs_human_reason=c.needs_human_reason[:500] if c.needs_human_reason else None,
            order_numbers=[n[:100] for n in c.order_numbers[:20]],
            invoice_numbers=[n[:100] for n in c.invoice_numbers[:20]],
            customer_name=c.customer_name[:255] if c.customer_name else None,
        ),
        sources=[
            SourcePayload(type=s.type.value, reference=s.reference[:255])
            for s in (draft.sources if draft else ())
        ][:20],
        tool_calls=[
            ToolCallPayload(
                tool=t.tool,
                arguments=t.arguments,
                result=t.result,
                status=t.status,
                error=t.error[:500] if t.error else None,
                duration_ms=t.duration_ms,
                origin=t.origin,
            )
            for t in result.tool_calls
        ][:50],
        duration_ms=result.duration_ms,
        usage=usage_from_calls(result.calls),
        thread_id=result.thread_id if pending else None,
        pending_action=PendingActionPayload(
            action_id=uuid.UUID(pending.action_id),
            tool=pending.tool,
            arguments=pending.arguments,
            summary=pending.summary[:500],
        )
        if pending
        else None,
        specialists=[s.value for s in result.specialists][:4],
        mode=mode_of(cause),
    )


def failed_event(
    cause: Cause, error: AgentError | None, message: str | None = None
) -> EmailProcessingFailed:
    return EmailProcessingFailed(
        trace_id=cause.trace_id,
        tenant_id=cause.tenant_id,
        email_id=cause.email_id,
        causation_id=cause.event_id,
        error_code=error.code if error else "internal",
        message=(message or (error.cause.message if error else "Error interno."))[:500],
        retryable=False,
        usage=usage_from_calls(error.calls if error else ()),
        mode=mode_of(cause),
    )
