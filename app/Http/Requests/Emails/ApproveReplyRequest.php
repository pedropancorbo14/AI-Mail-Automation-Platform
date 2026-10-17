<?php

namespace App\Http\Requests\Emails;

class ApproveReplyRequest extends ReviewEmailRequest
{
    public function rules(): array
    {
        return [
            // Opcional: el texto que la persona ha dejado (editado o no). Sin él, el de la IA tal cual.
            'body' => ['sometimes', 'required', 'string', 'max:' . self::MAX_BODY_CHARS],
        ];
    }
}
