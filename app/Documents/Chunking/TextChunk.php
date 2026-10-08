<?php

namespace App\Documents\Chunking;

final readonly class TextChunk
{
    /**
     * @param  array{page?: int, heading?: string}  $metadata  Origen (de la sección de la que sale).
     */
    public function __construct(
        public string $text,
        public array $metadata,
        public int $estimatedTokens,
    ) {
    }
}
