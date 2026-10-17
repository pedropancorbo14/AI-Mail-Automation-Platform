"""Cerebro de IA de AI Mail Automation Platform.

Capas (arquitectura hexagonal, ver README):
domain -> application -> agents / infrastructure -> entrypoints.
Las dependencias solo apuntan hacia dentro; `lint-imports` lo comprueba.
"""

from importlib.metadata import PackageNotFoundError, version

try:
    __version__ = version("ai-service")
except PackageNotFoundError:  # pragma: no cover - solo sin instalar el paquete
    __version__ = "0.0.0"
