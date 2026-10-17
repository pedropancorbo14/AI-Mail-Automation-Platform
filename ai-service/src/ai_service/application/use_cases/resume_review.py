"""Caso de uso: una persona decidió sobre la acción pendiente; reanudar el grafo."""

from ai_service.application.contracts.events import EmailReviewResumed
from ai_service.application.errors import AgentError
from ai_service.application.mappers import failed_event, processed_event
from ai_service.application.ports import EmailAgentPort, EventBusPort
from ai_service.application.use_cases.process_email import ProcessingResult
from ai_service.domain.email import TenantId


class ResumeReview:
    """Reanuda la ejecución en pausa y publica un nuevo `email.processed.v1`
    (`causation_id` = la reanudación).

    - Pausa inexistente (caducó o ya se resolvió: p. ej. una entrega repetida)
      → `email.processing_failed.v1` con `expired`.
    - Error temporal del modelo → se relanza: el consumidor no confirma y se
      reintenta (la pausa sigue; la acción es idempotente).
    - `ThreadMismatchError` (otra empresa, otro email u otra acción) se
      propaga: el consumidor lo manda a la cola de errores sin aplicar nada.
    """

    def __init__(self, agent: EmailAgentPort, bus: EventBusPort) -> None:
        self._agent = agent
        self._bus = bus

    async def execute(self, event: EmailReviewResumed) -> ProcessingResult:
        result: ProcessingResult
        try:
            outcome = await self._agent.resume(
                event.thread_id,
                tenant_id=TenantId(event.tenant_id),
                email_id=event.email_id,
                action_id=str(event.action_id),
                approved=event.approved,
            )
        except AgentError as error:
            if error.retryable:
                raise
            result = ProcessingResult("failed", failed_event(event, error))
        else:
            result = ProcessingResult("processed", processed_event(event, outcome))

        await self._bus.publish(result.event)
        return result
