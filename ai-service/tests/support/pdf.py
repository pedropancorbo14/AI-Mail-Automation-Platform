"""PDFs mínimos pero válidos (una página por texto, Helvetica, WinAnsi) para
probar la extracción sin guardar binarios en el repo (como `Tests\\Support\\Pdf`
de Laravel)."""


def make_pdf(pages: list[str]) -> bytes:
    """Texto vacío = página sin texto (como un escaneo)."""
    objects: dict[int, bytes] = {}
    page_ids: list[int] = []
    next_id = 4  # 1 catálogo, 2 árbol de páginas, 3 fuente
    for text in pages:
        page_id, content_id = next_id, next_id + 1
        next_id += 2
        page_ids.append(page_id)
        stream = b""
        if text:
            stream = b"BT /F1 12 Tf 72 720 Td 14 TL\n"
            for line in text.split("\n"):
                encoded = line.encode("cp1252")
                escaped = (
                    encoded.replace(b"\\", b"\\\\").replace(b"(", b"\\(").replace(b")", b"\\)")
                )
                stream += b"(" + escaped + b") Tj T*\n"
            stream += b"ET"
        objects[page_id] = (
            f"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] "
            f"/Resources << /Font << /F1 3 0 R >> >> /Contents {content_id} 0 R >>"
        ).encode()
        objects[content_id] = (
            f"<< /Length {len(stream)} >>\nstream\n".encode() + stream + b"\nendstream"
        )
    objects[1] = b"<< /Type /Catalog /Pages 2 0 R >>"
    kids = " ".join(f"{i} 0 R" for i in page_ids)
    objects[2] = f"<< /Type /Pages /Kids [{kids}] /Count {len(page_ids)} >>".encode()
    objects[3] = (
        b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>"
    )

    pdf = b"%PDF-1.4\n"
    offsets: dict[int, int] = {}
    for obj_id in sorted(objects):
        offsets[obj_id] = len(pdf)
        pdf += f"{obj_id} 0 obj\n".encode() + objects[obj_id] + b"\nendobj\n"
    xref = len(pdf)
    pdf += f"xref\n0 {len(objects) + 1}\n0000000000 65535 f \n".encode()
    for obj_id in sorted(offsets):
        pdf += f"{offsets[obj_id]:010d} 00000 n \n".encode()
    pdf += (
        f"trailer << /Size {len(objects) + 1} /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF\n".encode()
    )
    return pdf
