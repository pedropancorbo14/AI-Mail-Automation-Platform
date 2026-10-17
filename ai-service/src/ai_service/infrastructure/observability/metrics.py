"""Métricas Prometheus del servicio (prefijo `aiservice_`).

Registro propio (no el global de `prometheus_client`): cada app o test crea el
suyo y no hay errores de "métrica duplicada" ni estado compartido entre tests.
Cada proceso expone sus métricas en su `/metrics`; Prometheus suma las réplicas
(en Kubernetes, una serie por pod).
"""

import platform

from prometheus_client import CONTENT_TYPE_LATEST as PROMETHEUS_CONTENT_TYPE
from prometheus_client import (
    CollectorRegistry,
    Gauge,
    GCCollector,
    PlatformCollector,
    ProcessCollector,
    generate_latest,
)

from ai_service import __version__

__all__ = ["PROMETHEUS_CONTENT_TYPE", "ServiceMetrics"]


class ServiceMetrics:
    """Catálogo de métricas. Las de cada pieza se añaden aquí en su tarea."""

    def __init__(self, environment: str) -> None:
        self.registry = CollectorRegistry()
        ProcessCollector(registry=self.registry)
        PlatformCollector(registry=self.registry)
        GCCollector(registry=self.registry)

        self.build_info = Gauge(
            "aiservice_build_info",
            "Versión del servicio en ejecución (valor siempre 1).",
            ["version", "python", "environment"],
            registry=self.registry,
        )
        self.build_info.labels(__version__, platform.python_version(), environment).set(1)

    def render(self) -> bytes:
        return generate_latest(self.registry)
