<?php

namespace App\AI\Prompts;

use App\Models\AiPromptVersion;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;

/**
 * Guarda cada versión de prompt la primera vez que se usa y devuelve su
 * fila (para enlazarla desde ai_requests). Si el contenido de una versión
 * ya guardada ha cambiado, falla: hay que subir la versión.
 */
class PromptRegistry
{
    /** @var array<string, AiPromptVersion> Caché por proceso (los workers viven mucho). */
    private array $resolved = [];

    public function resolve(Prompt $prompt): AiPromptVersion
    {
        $hash = self::hash($prompt);
        $cacheKey = "{$prompt->key()}:{$prompt->version()}:{$hash}";

        return $this->resolved[$cacheKey] ??= $this->store($prompt, $hash);
    }

    public static function hash(Prompt $prompt): string
    {
        return hash('sha256', $prompt->system() . "\0" . json_encode($prompt->outputSchema()));
    }

    private function store(Prompt $prompt, string $hash): AiPromptVersion
    {
        $attributes = ['key' => $prompt->key(), 'version' => $prompt->version()];

        try {
            $version = AiPromptVersion::firstOrCreate($attributes, [
                'system_prompt' => $prompt->system(),
                'output_schema' => $prompt->outputSchema(),
                'content_hash' => $hash,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Otro worker la creó a la vez.
            $version = AiPromptVersion::where($attributes)->firstOrFail();
        }

        if ($version->content_hash !== $hash) {
            throw new LogicException(
                "El prompt {$prompt->key()} v{$prompt->version()} ha cambiado sin subir de versión: incrementa version().",
            );
        }

        return $version;
    }
}
