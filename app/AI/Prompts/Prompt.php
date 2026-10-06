<?php

namespace App\AI\Prompts;

/**
 * Prompt versionado. Cualquier cambio en system() o outputSchema() exige
 * subir version(): PromptRegistry lo comprueba con un hash y falla si no.
 */
interface Prompt
{
    /** Identificador estable, p. ej. "email_classification". */
    public function key(): string;

    public function version(): int;

    public function system(): string;

    /** @return array<string, mixed>|null Esquema JSON de la respuesta (structured outputs). */
    public function outputSchema(): ?array;
}
