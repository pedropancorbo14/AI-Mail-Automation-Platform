<?php

namespace App\Http\Requests\Emails;

use App\AI\Classification\EmailIntent;
use App\Mail\Enums\EmailStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filtros del listado de emails del panel. */
class ListEmailsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(EmailStatus::class)],
            'intent' => ['nullable', Rule::enum(EmailIntent::class)],
            'mailbox' => ['nullable', 'integer'],
            // Texto libre: asunto, email o nombre del remitente.
            'q' => ['nullable', 'string', 'max:200'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
