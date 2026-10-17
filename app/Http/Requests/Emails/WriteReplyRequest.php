<?php

namespace App\Http\Requests\Emails;

class WriteReplyRequest extends ReviewEmailRequest
{
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:' . self::MAX_BODY_CHARS],
        ];
    }
}
