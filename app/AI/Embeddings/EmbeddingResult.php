<?php

namespace App\AI\Embeddings;

final readonly class EmbeddingResult
{
    /**
     * @param  list<list<float>>  $vectors  Uno por texto, en el orden recibido.
     */
    public function __construct(
        public array $vectors,
        public string $model,
        public int $tokens,
        public int $latencyMs,
        public int $requests = 1,
    ) {
    }
}
