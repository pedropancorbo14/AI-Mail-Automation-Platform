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
    Counter,
    Gauge,
    GCCollector,
    Histogram,
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

        # --- Consumidor de eventos ---
        self.events = Counter(
            "aiservice_events_total",
            "Mensajes de email.received.v1 tratados, por resultado: processed (publicado "
            "email.processed.v1), failed (publicado email.processing_failed.v1), retry (error "
            "temporal: se reintentará), invalid (no cumple el contrato: a la cola de errores), "
            "duplicate (ya procesado), dead_lettered (agotó los intentos).",
            ["result"],
            registry=self.registry,
        )
        self.event_duration = Histogram(
            "aiservice_event_processing_seconds",
            "Tiempo de procesar un email (grafo + publicar), por resultado.",
            ["result"],
            buckets=(0.5, 1, 2.5, 5, 10, 20, 30, 60, 120, 240, 300),
            registry=self.registry,
        )
        self.decisions = Counter(
            "aiservice_decisions_total",
            "Decisiones recomendadas por el grafo.",
            ["decision", "category"],
            registry=self.registry,
        )
        self.llm_calls = Counter(
            "aiservice_llm_calls_total",
            "Llamadas al modelo (con fallback de servidor, una por modelo que intervino).",
            ["purpose", "model", "status"],
            registry=self.registry,
        )
        self.llm_tokens = Counter(
            "aiservice_llm_tokens_total",
            "Tokens facturados por tipo (input, output, cache_write, cache_read).",
            ["purpose", "model", "type"],
            registry=self.registry,
        )
        self.llm_latency = Histogram(
            "aiservice_llm_call_seconds",
            "Latencia de cada llamada al modelo.",
            ["purpose", "model"],
            buckets=(0.5, 1, 2, 4, 8, 15, 30, 60, 120),
            registry=self.registry,
        )
        self.stream_pending = Gauge(
            "aiservice_stream_pending",
            "Mensajes leídos por el grupo y aún sin confirmar.",
            registry=self.registry,
        )
        self.stream_lag = Gauge(
            "aiservice_stream_lag",
            "Mensajes del stream que el grupo aún no ha leído.",
            registry=self.registry,
        )
        self.heartbeat = Gauge(
            "aiservice_consumer_heartbeat_timestamp_seconds",
            "Última vuelta del bucle del consumidor (para detectar un consumidor colgado).",
            registry=self.registry,
        )

    def render(self) -> bytes:
        return generate_latest(self.registry)
