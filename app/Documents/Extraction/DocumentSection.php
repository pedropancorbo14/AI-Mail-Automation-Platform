<?php

namespace App\Documents\Extraction;

/** Parte del texto de un documento y de dónde sale (para citar la fuente). */
final readonly class DocumentSection
{
    /**
     * @param  array{page?: int, heading?: string}  $metadata
     */
    public function __construct(
        public string $text,
        public array $metadata = [],
    ) {
    }
}
