<?php

namespace App\AI\Data;

/** Tokens facturados por una petición. */
final readonly class LLMUsage
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        /** Tokens escritos en la caché de prompts (más caros que la entrada normal). */
        public int $cacheWriteTokens = 0,
        /** Tokens leídos de la caché de prompts (mucho más baratos). */
        public int $cacheReadTokens = 0,
    ) {
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens + $this->cacheWriteTokens + $this->cacheReadTokens;
    }
}
