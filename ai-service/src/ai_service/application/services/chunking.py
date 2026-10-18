"""Troceado del texto de un documento para indexarlo (el mismo algoritmo que
usaba Laravel, así los fragmentos y las citas no cambian al migrar):

- agrupa párrafos completos hasta `max_chars`; un párrafo más largo se parte
  por frases y una frase gigante por palabras;
- cada fragmento empieza con el final del anterior (`overlap_chars`) para no
  perder ideas que caen en el corte;
- nunca mezcla secciones (páginas o encabezados): la cita es siempre exacta.
"""

import math
import re
from collections.abc import Iterable

from ai_service.domain.knowledge import DocumentSection, TextChunk


class TextChunker:
    def __init__(self, max_chars: int = 2000, overlap_chars: int = 200) -> None:
        if max_chars < 100 or overlap_chars < 0 or overlap_chars >= max_chars / 2:
            raise ValueError("Tamaño de fragmento o solapamiento no válidos.")
        self._max = max_chars
        self._overlap = overlap_chars

    def chunk(self, sections: Iterable[DocumentSection]) -> list[TextChunk]:
        return [
            TextChunk(text, section.metadata, estimate_tokens(text))
            for section in sections
            for text in self._chunk_text(section.text)
        ]

    def _chunk_text(self, text: str) -> list[str]:
        chunks: list[str] = []
        current = ""
        for piece in self._pieces(text):
            candidate = piece if current == "" else f"{current}\n\n{piece}"
            if len(candidate) <= self._max:
                current = candidate
                continue
            chunks.append(current)
            overlap = self._tail(current)
            joined = f"{overlap}\n\n{piece}"
            current = joined if overlap and len(joined) <= self._max else piece
        if current:
            chunks.append(current)
        return chunks

    def _pieces(self, text: str) -> list[str]:
        """Unidades que no se parten: párrafos; si no caben, frases; si una
        frase no cabe, grupos de palabras."""
        pieces: list[str] = []
        for raw in re.split(r"\n{2,}", text.strip()):
            paragraph = raw.strip()
            if not paragraph:
                continue
            if len(paragraph) <= self._max:
                pieces.append(paragraph)
                continue
            for sentence in re.split(r"(?<=[.!?…])\s+", paragraph):
                pieces.extend(
                    [sentence] if len(sentence) <= self._max else self._split_words(sentence)
                )
        return pieces

    def _split_words(self, sentence: str) -> list[str]:
        parts: list[str] = []
        current = ""
        for word in re.split(r"\s+", sentence):
            # Una "palabra" más larga que el fragmento (p. ej. base64) se corta a lo bruto.
            for start in range(0, max(len(word), 1), self._max):
                fragment = word[start : start + self._max]
                candidate = fragment if current == "" else f"{current} {fragment}"
                if len(candidate) > self._max:
                    parts.append(current)
                    candidate = fragment
                current = candidate
        if current:
            parts.append(current)
        return parts

    def _tail(self, chunk: str) -> str:
        """Final del fragmento para repetir al principio del siguiente, desde una
        palabra completa."""
        if self._overlap == 0 or len(chunk) <= self._overlap:
            return ""
        tail = chunk[-self._overlap :]
        space = tail.find(" ")
        return (tail if space == -1 else tail[space + 1 :]).strip()


def estimate_tokens(text: str) -> int:
    """Estimación barata (~4 caracteres por token)."""
    return math.ceil(len(text) / 4)
