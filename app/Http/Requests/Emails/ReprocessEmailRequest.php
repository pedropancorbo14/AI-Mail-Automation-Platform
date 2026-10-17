<?php

namespace App\Http\Requests\Emails;

use App\AI\Remote\AIEngine;
use Closure;

/**
 * Reprocesar con la IA, con una indicación opcional de la persona. La
 * indicación solo la entiende ai-service (AI_ENGINE=python): con el motor de
 * Laravel se rechaza en vez de ignorarla en silencio.
 */
class ReprocessEmailRequest extends ReviewEmailRequest
{
    /** El mismo límite que review_instruction en el contrato email.received.v1. */
    public const int MAX_INSTRUCTION_CHARS = 2000;

    public function rules(): array
    {
        return [
            'instruction' => [
                'nullable',
                'string',
                'max:' . self::MAX_INSTRUCTION_CHARS,
                function (string $attribute, mixed $value, Closure $fail) {
                    if (is_string($value) && trim($value) !== '' && ! AIEngine::current()->isRemote()) {
                        $fail('Las indicaciones para la IA solo están disponibles con el servicio de IA (ai-service).');
                    }
                },
            ],
        ];
    }
}
