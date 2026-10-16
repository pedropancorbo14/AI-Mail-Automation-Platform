<?php

namespace App\Http\Requests\Audit;

use App\Http\Controllers\Api\V1\AuditLogController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filtros del registro de auditoría del panel. */
class ListAuditLogsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Primera parte de la acción: "user" = user.created, user.updated...
            'category' => ['nullable', Rule::in(AuditLogController::CATEGORIES)],
            'user' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
