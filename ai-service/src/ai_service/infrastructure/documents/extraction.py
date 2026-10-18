"""Texto de un documento por secciones con su origen (para citar): una por
página en PDF, una por encabezado en Markdown, una sola en texto y HTML.
Mismo comportamiento que tenía Laravel (y los mismos mensajes de error)."""

import io
import re
from html.parser import HTMLParser

from pypdf import PdfReader

from ai_service.application.errors import DocumentProcessingError
from ai_service.domain.knowledge import DocumentSection

SUPPORTED = {
    "application/pdf": "pdf",
    "text/plain": "text",
    "text/markdown": "markdown",
    "text/html": "html",
}
_HEADING = re.compile(r"^\s{0,3}#{1,6}\s+(.+?)\s*#*\s*$")


class DocumentTextExtractor:
    """Implementa `TextExtractorPort`."""

    def extract(self, content: bytes, mime_type: str) -> list[DocumentSection]:
        kind = SUPPORTED.get(mime_type)
        if kind == "pdf":
            sections = _pdf(content)
        elif kind == "markdown":
            sections = _markdown(_decode(content))
        elif kind == "html":
            sections = [DocumentSection(_html_to_text(_decode(content)))]
        elif kind == "text":
            sections = [DocumentSection(_decode(content))]
        else:
            raise DocumentProcessingError(
                "unsupported",
                f"Formato no soportado ({mime_type}). Se admiten PDF, TXT, Markdown y HTML.",
            )

        cleaned = [DocumentSection(_normalize(s.text), s.metadata) for s in sections]
        cleaned = [s for s in cleaned if s.text]
        if not cleaned:
            if kind == "pdf":
                raise DocumentProcessingError(
                    "no_text",
                    "El PDF no contiene texto extraíble (¿es un documento escaneado? "
                    "El OCR no está soportado todavía).",
                )
            raise DocumentProcessingError("empty", "El documento está vacío.")
        return cleaned


def _pdf(content: bytes) -> list[DocumentSection]:
    try:
        reader = PdfReader(io.BytesIO(content))
        if reader.is_encrypted:
            raise ValueError("cifrado")
        return [
            DocumentSection(_clean(page.extract_text() or ""), {"page": index + 1})
            for index, page in enumerate(reader.pages)
        ]
    except Exception as error:
        raise DocumentProcessingError(
            "unreadable", "No se pudo leer el PDF (¿está dañado o protegido con contraseña?)."
        ) from error


def _markdown(content: str) -> list[DocumentSection]:
    """Una sección por encabezado; el encabezado se queda en el texto (da
    contexto). Un encabezado sin texto debajo se une a la sección siguiente."""
    sections: list[DocumentSection] = []
    heading: str | None = None
    lines: list[str] = []
    has_text = False
    for line in content.splitlines():
        match = _HEADING.match(line)
        if match:
            if has_text:
                sections.append(_section(lines, heading))
                lines = []
            heading = match.group(1)
            lines.append(line)
            has_text = False
            continue
        lines.append(line)
        has_text = has_text or line.strip() != ""
    sections.append(_section(lines, heading))
    return sections


def _section(lines: list[str], heading: str | None) -> DocumentSection:
    return DocumentSection("\n".join(lines), {"heading": heading} if heading else {})


class _TextOnly(HTMLParser):
    _BLOCKS = frozenset(
        {"p", "div", "br", "li", "tr", "h1", "h2", "h3", "h4", "h5", "h6", "table", "ul", "ol"}
    )
    _SKIP = frozenset({"script", "style", "head", "title"})

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.parts: list[str] = []
        self._skipping = 0

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        if tag in self._SKIP:
            self._skipping += 1
        elif tag in self._BLOCKS:
            self.parts.append("\n\n" if tag != "br" else "\n")

    def handle_endtag(self, tag: str) -> None:
        if tag in self._SKIP and self._skipping:
            self._skipping -= 1
        elif tag in self._BLOCKS:
            self.parts.append("\n\n")

    def handle_data(self, data: str) -> None:
        if not self._skipping:
            self.parts.append(data)


def _html_to_text(html: str) -> str:
    parser = _TextOnly()
    parser.feed(html)
    parser.close()
    return "".join(parser.parts)


def _decode(content: bytes) -> str:
    return _clean(content.decode("utf-8", errors="replace"))


def _clean(text: str) -> str:
    """Sin bytes NUL (PostgreSQL los rechaza)."""
    return text.replace("\x00", "")


def _normalize(text: str) -> str:
    """Espacios razonables: como mucho una línea en blanco seguida."""
    text = re.sub(r"[ \t\u00a0]+", " ", text.replace("\r\n", "\n").replace("\r", "\n"))
    text = re.sub(r" *\n *", "\n", text)
    return re.sub(r"\n{3,}", "\n\n", text).strip()
