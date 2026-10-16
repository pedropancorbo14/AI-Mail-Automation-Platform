<?php

namespace App\Http\Requests\Users;

use App\Models\Role;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    /**
     * Se ejecuta antes que la validación: un usuario de otra empresa da 404
     * aunque los datos enviados sean inválidos (no revela que existe).
     */
    public function authorize(): Response
    {
        return Gate::inspect('update', [$this->route('user'), $this->input('role')]);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes', 'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->route('user')),
            ],
            'password' => ['sometimes', 'required', 'string', Password::defaults()],
            'role' => ['sometimes', 'required', Rule::in(array_keys(Role::CATALOG))],
        ];
    }
}
