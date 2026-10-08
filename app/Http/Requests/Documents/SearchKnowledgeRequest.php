<?php

namespace App\Http\Requests\Documents;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SearchKnowledgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Document::class);
    }

    public function rules(): array
    {
        return [
            'query' => ['required', 'string', 'max:2000'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ];
    }
}
