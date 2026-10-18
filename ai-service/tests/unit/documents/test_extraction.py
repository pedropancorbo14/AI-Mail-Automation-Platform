"""Extracción de texto por secciones (para citar), con los errores de Laravel."""

import pytest

from ai_service.application.errors import DocumentProcessingError
from ai_service.infrastructure.documents.extraction import DocumentTextExtractor
from tests.support.pdf import make_pdf

extract = DocumentTextExtractor().extract


def test_pdf_one_section_per_page_with_accents_and_empty_pages_out() -> None:
    sections = extract(make_pdf(["Política de envíos", "", "Página tres"]), "application/pdf")

    assert [(s.text, dict(s.metadata)) for s in sections] == [
        ("Política de envíos", {"page": 1}),
        ("Página tres", {"page": 3}),
    ]


def test_markdown_one_section_per_heading_and_empty_headings_join_the_next() -> None:
    markdown = "Intro\n\n# Envíos\n## Canarias\nCuesta 14,95 €.\n\n## Baleares\n9,95 €."

    sections = extract(markdown.encode(), "text/markdown")

    assert [(s.text, dict(s.metadata)) for s in sections] == [
        ("Intro", {}),
        ("# Envíos\n## Canarias\nCuesta 14,95 €.", {"heading": "Canarias"}),
        ("## Baleares\n9,95 €.", {"heading": "Baleares"}),
    ]


def test_html_without_scripts_and_plain_text() -> None:
    html = b"<html><head><title>x</title><script>alert(1)</script></head><body><p>Hola</p><p>Mundo &amp; m\xc3\xa1s</p></body></html>"  # noqa: E501
    [section] = extract(html, "text/html")
    assert section.text == "Hola\n\nMundo & más"

    [plain] = extract(b"  Texto   con\x00 espacios  \n\n\n\nfin ", "text/plain")
    assert plain.text == "Texto con espacios\n\nfin"


@pytest.mark.parametrize(
    ("content", "mime", "code"),
    [
        (b"hola", "application/msword", "unsupported"),
        (b"   \n ", "text/plain", "empty"),
        (make_pdf(["", ""]), "application/pdf", "no_text"),
        (b"%PDF-1.4 roto", "application/pdf", "unreadable"),
    ],
)
def test_documents_that_cannot_be_indexed(content: bytes, mime: str, code: str) -> None:
    with pytest.raises(DocumentProcessingError) as error:
        extract(content, mime)
    assert error.value.code == code
