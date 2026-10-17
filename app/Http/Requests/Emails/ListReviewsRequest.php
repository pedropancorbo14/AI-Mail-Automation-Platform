<?php

namespace App\Http\Requests\Emails;

use App\Automation\Review\ReviewDecision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filtros del historial de decisiones de la revisión humana. */
class ListReviewsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'decision' => ['nullable', Rule::enum(ReviewDecision::class)],
            'user' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
