<?php

namespace App\Documents\Extraction;

use App\Documents\Exceptions\DocumentProcessingException;
use App\Mail\Support\HtmlToText;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

/**
 * Texto de un documento, por secciones con su origen: una por página en
 * PDF, una por encabezado en Markdown, una sola en texto y HTML. El texto
 * sale saneado (UTF-8 válido, sin NUL) para PostgreSQL.
 */
class DocumentTextExtractor
{
    /** Tipos admitidos → formato. */
    public const array SUPPORTED = [
        'application/pdf' => 'pdf',
        'text/plain' => 'text',
        'text/markdown' => 'markdown',
        'text/x-markdown' => 'markdown',
        'text/html' => 'html',
    ];

    /** Extensiones admitidas → tipo (los navegadores mandan text/plain para .md). */
    public const array EXTENSIONS = [
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
        'md' => 'text/markdown',
        'markdown' => 'text/markdown',
        'html' => 'text/html',
        'htm' => 'text/html',
    ];

    /** Tipo MIME efectivo a partir del nombre (manda la extensión) o del tipo detectado. */
    public static function mimeFor(string $filename, ?string $detectedMime = null): ?string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return self::EXTENSIONS[$extension]
            ?? (isset(self::SUPPORTED[(string) $detectedMime]) ? $detectedMime : null);
    }

    /**
     * @return list<DocumentSection> sin secciones vacías
     *
     * @throws DocumentProcessingException
     */
    public function extract(string $content, string $mimeType): array
    {
        $sections = match (self::SUPPORTED[$mimeType] ?? null) {
            'pdf' => $this->pdf($content),
            'markdown' => $this->markdown(self::clean($content)),
            'html' => [new DocumentSection(HtmlToText::convert(self::clean($content)))],
            'text' => [new DocumentSection(self::clean($content))],
            default => throw new DocumentProcessingException(
                "Formato no soportado ({$mimeType}). Se admiten PDF, TXT, Markdown y HTML.",
            ),
        };

        $sections = array_values(array_filter(
            array_map(fn (DocumentSection $s) => new DocumentSection(self::normalizeSpaces($s->text), $s->metadata), $sections),
            fn (DocumentSection $s) => $s->text !== '',
        ));

        if ($sections === []) {
            throw new DocumentProcessingException(
                $mimeType === 'application/pdf'
                    ? 'El PDF no contiene texto extraíble (¿es un documento escaneado? El OCR no está soportado todavía).'
                    : 'El documento está vacío.',
            );
        }

        return $sections;
    }

    /** @return list<DocumentSection> */
    private function pdf(string $content): array
    {
        try {
            $pages = (new PdfParser())->parseContent($content)->getPages();
        } catch (Throwable $e) {
            throw new DocumentProcessingException('No se pudo leer el PDF (¿está dañado o protegido con contraseña?).', previous: $e);
        }

        $sections = [];

        foreach ($pages as $index => $page) {
            $sections[] = new DocumentSection(self::clean($page->getText()), ['page' => $index + 1]);
        }

        return $sections;
    }

    /**
     * Una sección por encabezado (# ...); el texto anterior al primero va sin
     * encabezado. El encabezado se mantiene dentro del texto (aporta contexto).
     *
     * @return list<DocumentSection>
     */
    private function markdown(string $content): array
    {
        $sections = [];
        $heading = null;
        $lines = [];

        $hasText = false;

        foreach (preg_split('/\R/', $content) as $line) {
            if (preg_match('/^\s{0,3}#{1,6}\s+(.+?)\s*#*\s*$/', $line, $match)) {
                if ($hasText) {
                    $sections[] = new DocumentSection(implode("\n", $lines), $heading ? ['heading' => $heading] : []);
                    $lines = [];
                }

                // Encabezados sin texto debajo (p. ej. "# Título" seguido de "## Apartado")
                // se unen a la sección siguiente: un trozo con solo un título no sirve.
                $heading = $match[1];
                $lines[] = $line;
                $hasText = false;

                continue;
            }

            $lines[] = $line;
            $hasText = $hasText || trim($line) !== '';
        }

        $sections[] = new DocumentSection(implode("\n", $lines), $heading ? ['heading' => $heading] : []);

        return $sections;
    }

    /** UTF-8 válido y sin bytes NUL (PostgreSQL rechaza ambos). */
    private static function clean(string $text): string
    {
        return str_replace("\0", '', mb_scrub($text, 'UTF-8'));
    }

    /** Espacios y saltos de línea razonables: máximo una línea en blanco seguida. */
    private static function normalizeSpaces(string $text): string
    {
        $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', str_replace(["\r\n", "\r"], "\n", $text));
        $text = preg_replace("/ *\n */", "\n", $text);

        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
