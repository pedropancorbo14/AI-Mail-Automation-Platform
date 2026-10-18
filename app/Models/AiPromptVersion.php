<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Versión de un prompt del antiguo motor de IA de Laravel, tal como se envió al
 * modelo. Solo historial (lo enlazan peticiones antiguas de ai_requests): ya no
 * se crean filas; los prompts viven en ai-service (`clave@versión` en
 * `ai_requests.response.prompt`).
 */
#[Fillable(['key', 'version', 'system_prompt', 'output_schema', 'content_hash'])]
class AiPromptVersion extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'output_schema' => 'array',
        ];
    }
}
