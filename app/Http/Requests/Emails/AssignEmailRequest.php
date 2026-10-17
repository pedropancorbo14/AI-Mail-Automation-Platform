<?php

namespace App\Http\Requests\Emails;

use App\Models\Permission;
use App\Models\User;
use Closure;

class AssignEmailRequest extends ReviewEmailRequest
{
    public function rules(): array
    {
        return [
            // null = liberar el caso. Solo personas de la empresa que pueden revisar.
            'user_id' => ['present', 'nullable', 'integer', function (string $attribute, mixed $value, Closure $fail) {
                $user = User::query()->where('tenant_id', $this->user()->tenant_id)->find($value);

                if ($user === null || ! $user->hasPermission(Permission::EMAILS_REVIEW)) {
                    $fail('Esa persona no existe en la empresa o no puede revisar emails.');
                }
            }],
        ];
    }

    public function assignee(): ?User
    {
        $id = $this->validated('user_id');

        return $id === null ? null : User::query()->where('tenant_id', $this->user()->tenant_id)->findOrFail($id);
    }
}
