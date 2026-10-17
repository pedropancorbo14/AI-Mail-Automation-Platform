<?php

namespace App\AI\Remote;

/**
 * Qué motor de IA procesa los emails (AI_ENGINE):
 * - laravel: el de las Fases 5–9 (clasificador + agente con RAG y tools), en este proceso.
 * - python: el microservicio ai-service, por eventos en Redis Streams.
 */
enum AIEngine: string
{
    case Laravel = 'laravel';
    case Python = 'python';

    public static function current(): self
    {
        return self::tryFrom((string) config('ai.engine')) ?? self::Laravel;
    }

    public function isRemote(): bool
    {
        return $this === self::Python;
    }
}
