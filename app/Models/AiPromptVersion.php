<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Versión de un prompt tal como se envió al modelo. Inmutable: la crea
 * App\AI\Prompts\PromptRegistry al usar un prompt por primera vez. Sin
 * tenant: los prompts son de la plataforma, no de cada empresa.
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
