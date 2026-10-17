<?php

namespace App\Http\Requests\Emails;

use App\Models\EmailReply;
use Illuminate\Validation\Rule;

/** Comprobar un texto antes de enviarlo (avisos para la persona). */
class CheckReplyRequest extends ReviewEmailRequest
{
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:' . self::MAX_BODY_CHARS],
            // La propuesta de la IA en la que se basa (sus datos consultados cuentan como conocidos).
            'reply_id' => ['nullable', 'integer', Rule::exists('email_replies', 'id')->where('email_id', $this->route('email')->id)],
        ];
    }

    public function basedOn(): ?EmailReply
    {
        $id = $this->validated('reply_id');

        return $id === null ? null : $this->route('email')->replies()->find($id);
    }
}
