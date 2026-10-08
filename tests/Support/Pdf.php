<?php

namespace Tests\Support;

/**
 * Genera PDFs mínimos pero válidos (una página por texto, Helvetica,
 * WinAnsiEncoding) para probar la extracción sin guardar binarios en el repo.
 */
class Pdf
{
    /** @param  list<string>  $pages  Texto de cada página (sin texto = página vacía, como un escaneo). */
    public static function make(array $pages): string
    {
        $objects = [];
        $pageIds = [];
        $nextId = 4; // 1 catálogo, 2 árbol de páginas, 3 fuente

        foreach ($pages as $text) {
            $pageId = $nextId++;
            $contentId = $nextId++;
            $pageIds[] = $pageId;

            $stream = '';
            if ($text !== '') {
                $stream = "BT /F1 12 Tf 72 720 Td 14 TL\n";
                foreach (explode("\n", $text) as $line) {
                    $encoded = mb_convert_encoding($line, 'Windows-1252', 'UTF-8');
                    $stream .= '(' . addcslashes($encoded, '()\\') . ") Tj T*\n";
                }
                $stream .= 'ET';
            }

            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents {$contentId} 0 R >>";
            $objects[$contentId] = '<< /Length ' . strlen($stream) . " >>\nstream\n{$stream}\nendstream";
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', array_map(fn ($id) => "{$id} 0 R", $pageIds)) . '] /Count ' . count($pageIds) . ' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf . 'trailer << /Size ' . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
