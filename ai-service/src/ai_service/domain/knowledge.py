"""Fragmentos de la documentación de la empresa (RAG)."""

from collections.abc import Mapping
from dataclasses import dataclass, field
from typing import Any


@dataclass(frozen=True, slots=True)
class KnowledgeChunk:
    """Un trozo de un documento de la empresa, con lo necesario para citarlo."""

    chunk_id: int
    document_id: int
    title: str
    content: str
    similarity: float
    metadata: Mapping[str, Any] = field(default_factory=dict)

    @property
    def source(self) -> str:
        """Fuente citable, igual que en Laravel: "Título, pág. 3" o "Título, sección «X»"."""
        page = self.metadata.get("page")
        if page is not None:
            return f"{self.title}, pág. {page}"
        heading = self.metadata.get("heading")
        if heading:
            return f"{self.title}, sección «{heading}»"
        return self.title


@dataclass(frozen=True, slots=True)
class DocumentSection:
    """Texto de un trozo con origen propio (una página de un PDF, un apartado
    de un Markdown): los fragmentos nunca mezclan secciones, así la cita es exacta."""

    text: str
    metadata: Mapping[str, Any] = field(default_factory=dict)


@dataclass(frozen=True, slots=True)
class TextChunk:
    """Fragmento listo para indexar, con la metadata de su sección."""

    text: str
    metadata: Mapping[str, Any] = field(default_factory=dict)
    estimated_tokens: int = 0
