<?php

namespace App\Http\Requests\Emails;

/** Descartar la propuesta de la IA, o cerrar un caso sin responder: motivo opcional. */
class RejectReplyRequest extends ReviewEmailRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:' . self::MAX_REASON_CHARS],
        ];
    }
}
