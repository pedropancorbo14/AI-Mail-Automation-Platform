<?php

namespace App\Documents\Chunking;

use App\Documents\Extraction\DocumentSection;
use InvalidArgumentException;

/**
 * Trocea el texto de un documento para indexarlo:
 * - agrupa párrafos completos hasta $maxChars; un párrafo más largo se parte
 *   por frases y una frase gigante por palabras;
 * - cada trozo empieza con el final del anterior ($overlapChars) para no
 *   perder ideas que caen en el corte;
 * - nunca mezcla secciones (páginas/encabezados): la cita es siempre exacta.
 */
class TextChunker
{
    public function __construct(
        private readonly int $maxChars = 2000,
        private readonly int $overlapChars = 200,
    ) {
        if ($maxChars < 100 || $overlapChars < 0 || $overlapChars >= $maxChars / 2) {
            throw new InvalidArgumentException('Tamaño de trozo o solapamiento no válidos.');
        }
    }

    /**
     * @param  list<DocumentSection>  $sections
     * @return list<TextChunk>
     */
    public function chunk(array $sections): array
    {
        $chunks = [];

        foreach ($sections as $section) {
            foreach ($this->chunkText($section->text) as $text) {
                $chunks[] = new TextChunk($text, $section->metadata, self::estimateTokens($text));
            }
        }

        return $chunks;
    }

    /** Estimación barata (~4 caracteres por token); el proveedor da la cifra real. */
    public static function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    /** @return list<string> */
    private function chunkText(string $text): array
    {
        $pieces = $this->pieces($text);
        $chunks = [];
        $current = '';

        foreach ($pieces as $piece) {
            $candidate = $current === '' ? $piece : $current . "\n\n" . $piece;

            if (mb_strlen($candidate) <= $this->maxChars) {
                $current = $candidate;

                continue;
            }

            $chunks[] = $current;
            $overlap = $this->tail($current);
            $current = $overlap !== '' && mb_strlen($overlap . "\n\n" . $piece) <= $this->maxChars
                ? $overlap . "\n\n" . $piece
                : $piece;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * Unidades que no se parten: párrafos; si alguno no cabe, sus frases; si
     * una frase no cabe, grupos de palabras.
     *
     * @return list<string>
     */
    private function pieces(string $text): array
    {
        $pieces = [];

        foreach (preg_split("/\n{2,}/", trim($text)) as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            if (mb_strlen($paragraph) <= $this->maxChars) {
                $pieces[] = $paragraph;

                continue;
            }

            foreach (preg_split('/(?<=[.!?…])\s+/u', $paragraph) as $sentence) {
                array_push($pieces, ...(mb_strlen($sentence) <= $this->maxChars ? [$sentence] : $this->splitWords($sentence)));
            }
        }

        return $pieces;
    }

    /** @return list<string> */
    private function splitWords(string $sentence): array
    {
        $parts = [];
        $current = '';

        foreach (preg_split('/\s+/u', $sentence) as $word) {
            // Una "palabra" más larga que el trozo (p. ej. base64) se corta a lo bruto.
            foreach (mb_str_split($word, $this->maxChars) as $fragment) {
                $candidate = $current === '' ? $fragment : $current . ' ' . $fragment;

                if (mb_strlen($candidate) > $this->maxChars) {
                    $parts[] = $current;
                    $candidate = $fragment;
                }

                $current = $candidate;
            }
        }

        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    /** Final del trozo para repetir al principio del siguiente, empezando en una palabra completa. */
    private function tail(string $chunk): string
    {
        if ($this->overlapChars === 0 || mb_strlen($chunk) <= $this->overlapChars) {
            return '';
        }

        $tail = mb_substr($chunk, -$this->overlapChars);
        $space = mb_strpos($tail, ' ');

        return trim($space === false ? $tail : mb_substr($tail, $space + 1));
    }
}
