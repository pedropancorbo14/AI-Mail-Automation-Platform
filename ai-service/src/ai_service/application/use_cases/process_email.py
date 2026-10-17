"""Caso de uso: procesar un email recibido y publicar el resultado."""

from dataclasses import dataclass
from typing import Literal

from ai_service.application.contracts.events import EmailReceived
from ai_service.application.errors import AgentError
from ai_service.application.mappers import (
    email_from_event,
    failed_event,
    processed_event,
    thread_id_for,
)
from ai_service.application.ports import EmailAgentPort, EventBusPort, OutgoingEvent
from ai_service.domain.errors import DomainError

Outcome = Literal["processed", "failed"]


@dataclass(frozen=True, slots=True)
class ProcessingResult:
    outcome: Outcome
    event: OutgoingEvent


class ProcessEmail:
    """Ejecuta el agente sobre el email y publica `email.processed.v1`.

    - Error definitivo del modelo (clave, rechazo, respuesta inválida) →
      publica `email.processing_failed.v1` y termina: reintentar costaría
      dinero sin cambiar nada.
    - Error temporal (red, 429, 529) → relanza `AgentError`: el consumidor
      deja el mensaje sin confirmar y se reintenta; tras N entregas publica
      él el fallo definitivo.
    - Dato imposible de procesar (`DomainError`) → fallo definitivo.

    En modo sombra (`mode: shadow`) es igual, pero el agente no propone acciones
    y el resultado sale marcado: Laravel solo lo compara, nunca lo aplica.
    """

    def __init__(self, agent: EmailAgentPort, bus: EventBusPort) -> None:
        self._agent = agent
        self._bus = bus

    async def execute(self, received: EmailReceived) -> ProcessingResult:
        result: ProcessingResult
        try:
            email = email_from_event(received)
            outcome = await self._agent.run(
                email, thread_id=thread_id_for(received), shadow=received.mode == "shadow"
            )
        except AgentError as error:
            if error.retryable:
                raise
            result = ProcessingResult("failed", failed_event(received, error))
        except DomainError as error:
            result = ProcessingResult(
                "failed", failed_event(received, None, f"Email no procesable: {error}")
            )
        else:
            result = ProcessingResult("processed", processed_event(received, outcome))

        await self._bus.publish(result.event)
        return result
