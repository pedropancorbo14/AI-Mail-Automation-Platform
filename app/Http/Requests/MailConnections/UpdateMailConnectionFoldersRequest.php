<?php

namespace App\Http\Requests\MailConnections;

use App\Models\MailConnection;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Carpetas que se sincronizan además de la bandeja de entrada. */
class UpdateMailConnectionFoldersRequest extends FormRequest
{
    public function authorize(): Response
    {
        /** @var MailConnection $connection */
        $connection = $this->route('mailConnection');

        return Gate::inspect('update', $connection);
    }

    public function rules(): array
    {
        return [
            'folders' => ['present', 'array', 'max:50'],
            'folders.*' => ['required', 'string', 'max:255', 'distinct'],
        ];
    }

    /** @return list<string> */
    public function folders(): array
    {
        return array_values($this->validated('folders'));
    }
}
