"""Troceado: el mismo algoritmo que tenía Laravel (las citas no cambian al migrar)."""

import pytest

from ai_service.application.services.chunking import TextChunker, estimate_tokens
from ai_service.domain.knowledge import DocumentSection


def test_short_paragraphs_go_together_in_one_chunk() -> None:
    chunks = TextChunker(200, 20).chunk([DocumentSection("Uno.\n\nDos.\n\nTres.", {"page": 1})])

    assert [c.text for c in chunks] == ["Uno.\n\nDos.\n\nTres."]
    assert chunks[0].metadata == {"page": 1}
    assert chunks[0].estimated_tokens == estimate_tokens("Uno.\n\nDos.\n\nTres.")


def test_never_mixes_sections() -> None:
    chunks = TextChunker(200, 20).chunk(
        [DocumentSection("Página uno.", {"page": 1}), DocumentSection("Página dos.", {"page": 2})]
    )

    assert [(c.text, dict(c.metadata)) for c in chunks] == [
        ("Página uno.", {"page": 1}),
        ("Página dos.", {"page": 2}),
    ]


def test_long_text_is_split_with_overlap_from_a_whole_word() -> None:
    paragraphs = "\n\n".join(
        f"Párrafo número {i} con algunas palabras de relleno." for i in range(12)
    )

    chunks = TextChunker(150, 40).chunk([DocumentSection(paragraphs)])

    assert len(chunks) > 1
    assert all(len(c.text) <= 150 for c in chunks)
    # El siguiente empieza con el final del anterior (desde una palabra completa).
    tail = chunks[0].text[-40:]
    assert chunks[1].text.startswith(tail[tail.index(" ") + 1 :].strip())


def test_giant_sentences_and_words_are_cut() -> None:
    text = "palabra " * 80 + "x" * 450

    chunks = TextChunker(200, 0).chunk([DocumentSection(text)])

    assert all(len(c.text) <= 200 for c in chunks)
    assert "".join(c.text.replace(" ", "") for c in chunks).count("x") == 450


@pytest.mark.parametrize(("max_chars", "overlap"), [(50, 0), (200, 100), (200, -1)])
def test_invalid_sizes_are_rejected(max_chars: int, overlap: int) -> None:
    with pytest.raises(ValueError, match="no válidos"):
        TextChunker(max_chars, overlap)
