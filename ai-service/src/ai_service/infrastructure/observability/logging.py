"""Logs en JSON, una línea por entrada, a stdout (los recoge Promtail -> Loki).

Mismo criterio que el canal `json` de Laravel (Fase 11): el nivel va en
mayúsculas (`INFO`, `WARNING`, `ERROR`, como `level_name` de Monolog) para
que los paneles de Grafana filtren igual los dos servicios. Los datos de
contexto (`trace_id`, `tenant_id`, `email_id`) se añaden con `bind_context`
y salen en todas las líneas hasta `clear_context`.

También pasan por aquí los logs de la biblioteca estándar (uvicorn, httpx...),
así ninguna línea sale en texto plano.
"""

import logging
import sys
from typing import Any, TextIO

import structlog
from structlog.typing import EventDict, Processor, WrappedLogger

SERVICE_NAME = "ai-service"


def _rename_event_to_message(_: WrappedLogger, __: str, event_dict: EventDict) -> EventDict:
    event_dict["message"] = event_dict.pop("event", "")
    return event_dict


def _uppercase_level(_: WrappedLogger, __: str, event_dict: EventDict) -> EventDict:
    level = event_dict.get("level")
    if isinstance(level, str):
        event_dict["level"] = level.upper()
    return event_dict


def _add_service(_: WrappedLogger, __: str, event_dict: EventDict) -> EventDict:
    event_dict.setdefault("service", SERVICE_NAME)
    return event_dict


def configure_logging(level: str = "INFO", stream: TextIO | None = None) -> None:
    """Configura structlog y la biblioteca estándar. Se puede llamar varias veces."""
    shared: list[Processor] = [
        structlog.contextvars.merge_contextvars,
        structlog.stdlib.add_log_level,
        structlog.stdlib.add_logger_name,
        structlog.processors.TimeStamper(fmt="iso", utc=True, key="timestamp"),
        _add_service,
    ]

    structlog.configure(
        processors=[
            *shared,
            structlog.stdlib.ProcessorFormatter.wrap_for_formatter,
        ],
        logger_factory=structlog.stdlib.LoggerFactory(),
        wrapper_class=structlog.stdlib.BoundLogger,
        cache_logger_on_first_use=False,
    )

    formatter = structlog.stdlib.ProcessorFormatter(
        foreign_pre_chain=shared,
        processors=[
            structlog.stdlib.ProcessorFormatter.remove_processors_meta,
            structlog.processors.format_exc_info,
            _uppercase_level,
            _rename_event_to_message,
            structlog.processors.JSONRenderer(ensure_ascii=False),
        ],
    )

    handler = logging.StreamHandler(stream or sys.stdout)
    handler.setFormatter(formatter)

    root = logging.getLogger()
    root.handlers = [handler]
    root.setLevel(level)

    # uvicorn trae sus propios handlers: que todo pase por el raíz (JSON).
    for name in ("uvicorn", "uvicorn.error", "uvicorn.access"):
        uvicorn_logger = logging.getLogger(name)
        uvicorn_logger.handlers = []
        uvicorn_logger.propagate = True


def get_logger(name: str) -> structlog.stdlib.BoundLogger:
    logger: structlog.stdlib.BoundLogger = structlog.stdlib.get_logger(name)
    return logger


def bind_context(**values: Any) -> None:
    """Añade datos a todas las líneas de log de la tarea actual (contextvars)."""
    structlog.contextvars.bind_contextvars(**values)


def clear_context() -> None:
    structlog.contextvars.clear_contextvars()
