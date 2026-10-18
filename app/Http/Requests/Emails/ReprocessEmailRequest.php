<?php

namespace App\Http\Requests\Emails;

/**
 * Reprocesar con la IA, con una indicación opcional de la persona para
 * ai-service (`review_instruction` de email.received.v1).
 */
class ReprocessEmailRequest extends ReviewEmailRequest
{
    /** El mismo límite que review_instruction en el contrato email.received.v1. */
    public const int MAX_INSTRUCTION_CHARS = 2000;

    public function rules(): array
    {
        return [
            'instruction' => ['nullable', 'string', 'max:' . self::MAX_INSTRUCTION_CHARS],
        ];
    }
}
