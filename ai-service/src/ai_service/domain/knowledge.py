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
