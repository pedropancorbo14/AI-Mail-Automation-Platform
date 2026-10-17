"""POST /debug/process: procesa un email de prueba y devuelve lo que decidiría el
grafo, sin eventos ni Laravel. Para probar prompts a mano.

Llama al modelo de verdad (cuesta dinero): solo existe con
AI_SERVICE_DEBUG_ENDPOINTS=true y nunca en producción. No publica nada.
"""

from collections.abc import Callable
from dataclasses import asdict
from datetime import UTC, datetime
from typing import Any

from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, ConfigDict, Field

from ai_service.application.errors import AgentError, LLMError
from ai_service.application.ports import EmailAgentPort
from ai_service.domain.email import Email, EmailAddress, TenantId
from ai_service.domain.errors import DomainError


class DebugEmail(BaseModel):
    model_config = ConfigDict(populate_by_name=True, extra="forbid")

    tenant_id: int = Field(default=1, ge=1)
    tenant_name: str | None = Field(default="Empresa Demo", max_length=255)
    sender: str = Field(alias="from", max_length=320)
    from_name: str | None = Field(default=None, max_length=255)
    subject: str = Field(default="", max_length=998)
    body: str = Field(max_length=50_000)


def debug_router(agent_factory: Callable[[], EmailAgentPort]) -> APIRouter:
    router = APIRouter(prefix="/debug", tags=["debug"])
    agent: list[EmailAgentPort] = []  # se crea al primer uso (necesita la clave)

    @router.post("/process")
    async def process(payload: DebugEmail) -> dict[str, Any]:
        try:
            email = Email(
                email_id=1,
                tenant_id=TenantId(payload.tenant_id),
                sender=EmailAddress(payload.sender),
                sender_name=payload.from_name,
                subject=payload.subject,
                body=payload.body,
                received_at=datetime.now(UTC),
                tenant_name=payload.tenant_name,
            )
        except DomainError as error:
            raise HTTPException(status_code=422, detail=str(error)) from error

        try:
            if not agent:
                agent.append(agent_factory())
            result = await agent[0].run(email)
        except LLMError as error:  # p. ej. sin clave de Anthropic
            raise HTTPException(status_code=503, detail=error.message) from error
        except AgentError as error:
            raise HTTPException(
                status_code=502 if error.retryable else 422,
                detail={"error_code": error.code, "message": error.cause.message},
            ) from error

        c = result.classification
        return {
            "decision": result.outcome.decision.value,
            "reasons": list(result.outcome.reasons),
            "category": c.category.value,
            "classification": {
                "confidence": c.confidence.value,
                "language": c.language,
                "summary": c.summary,
                "sentiment": c.sentiment.value,
                "urgency": c.urgency.value,
                "injection_suspected": c.injection_suspected,
                "needs_human": c.needs_human,
                "needs_human_reason": c.needs_human_reason,
                "order_numbers": list(c.order_numbers),
            },
            "draft": None
            if result.draft is None
            else {
                "body": result.draft.body,
                "confidence": result.draft.confidence.value,
                "needs_human": result.draft.needs_human,
                "needs_human_reason": result.draft.needs_human_reason,
                "pending_actions": list(result.draft.pending_actions),
            },
            "calls": [asdict(call) for call in result.calls],
        }

    return router
