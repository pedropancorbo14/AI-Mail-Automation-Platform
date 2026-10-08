<?php

namespace App\Http\Requests\Documents;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Subida de un documento (multipart: file + title opcional). El tipo de
 * fichero lo valida DocumentService (por extensión: PDF, TXT, MD, HTML).
 */
class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Document::class);
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:' . config('ai.rag.max_upload_mb') * 1024],
            'title' => ['nullable', 'string', 'max:250'],
        ];
    }
}
