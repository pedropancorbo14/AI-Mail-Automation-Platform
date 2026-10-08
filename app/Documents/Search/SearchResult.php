<?php

namespace App\Documents\Search;

/** Trozo de documentación encontrado, con su fuente para poder citarla. */
final readonly class SearchResult
{
    /**
     * @param  array{page?: int, heading?: string}  $metadata
     * @param  float  $similarity  Coseno entre 0 y 1 (1 = idéntico).
     */
    public function __construct(
        public int $chunkId,
        public int $documentId,
        public string $documentTitle,
        public string $content,
        public array $metadata,
        public float $similarity,
    ) {
    }

    /** Fuente legible: "Política de devoluciones, pág. 3" / "…, sección Envíos". */
    public function source(): string
    {
        return $this->documentTitle . match (true) {
            isset($this->metadata['page']) => ", pág. {$this->metadata['page']}",
            isset($this->metadata['heading']) => ", sección «{$this->metadata['heading']}»",
            default => '',
        };
    }
}
