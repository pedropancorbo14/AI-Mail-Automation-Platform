"""Errores de la capa de aplicación.

Los adaptadores traducen los errores de sus bibliotecas a estos: el resto del
servicio nunca ve una excepción del SDK de Anthropic o de Redis.
"""

from typing import Literal

from ai_service.application.dto import LLMCall

ErrorCode = Literal["llm_config", "refusal", "invalid_output", "timeout", "internal"]


class LLMError(Exception):
    """Fallo al llamar al modelo. `calls` lleva lo facturado aunque haya fallado."""

    code: ErrorCode = "internal"
    retryable: bool = False

    def __init__(self, message: str, calls: tuple[LLMCall, ...] = ()) -> None:
        super().__init__(message)
        self.message = message
        self.calls = calls


class LLMUnavailableError(LLMError):
    """Red, 5xx, 529 sobrecargado, 429: se reintenta más tarde."""

    code: ErrorCode = "timeout"
    retryable = True


class LLMConfigurationError(LLMError):
    """Clave, saldo, modelo inexistente o petición rechazada: reintentar no sirve."""

    code: ErrorCode = "llm_config"


class LLMRefusalError(LLMError):
    """Los filtros de seguridad del modelo se negaron."""

    code: ErrorCode = "refusal"


class LLMInvalidOutputError(LLMError):
    """La respuesta no cumple el esquema o se cortó por max_tokens."""

    code: ErrorCode = "invalid_output"


class AgentError(Exception):
    """El agente no pudo terminar. Lleva todas las llamadas hechas (se facturan)."""

    def __init__(self, cause: LLMError, calls: tuple[LLMCall, ...]) -> None:
        super().__init__(cause.message)
        self.cause = cause
        self.calls = calls

    @property
    def retryable(self) -> bool:
        return self.cause.retryable

    @property
    def code(self) -> ErrorCode:
        return self.cause.code


class KnowledgeBaseError(Exception):
    """No se pudo consultar la base de conocimiento (PostgreSQL)."""
