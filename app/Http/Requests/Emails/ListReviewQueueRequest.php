<?php

namespace App\Http\Requests\Emails;

use App\AI\Classification\EmailIntent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filtros de la bandeja de revisión. */
class ListReviewQueueRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // mine = asignados a mí; unassigned = sin asignar; vacío = todos.
            'assigned' => ['nullable', Rule::in(['mine', 'unassigned'])],
            'intent' => ['nullable', Rule::enum(EmailIntent::class)],
            'mailbox' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
