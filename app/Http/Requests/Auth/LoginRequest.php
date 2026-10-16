<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Security\Audit\AuditLogger;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            // Nombre del token (ej. "panel", "integracion-crm"); permite
            // identificar y revocar cada token por separado.
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Devuelve el usuario si las credenciales son correctas. Mismo error para
     * email inexistente y contraseña incorrecta: no revela qué emails existen.
     *
     * @throws ValidationException
     */
    public function authenticate(): User
    {
        $user = User::with(['tenant', 'role'])->where('email', $this->string('email'))->first();

        if (! $user || ! Hash::check($this->string('password'), $user->password)) {
            // Con un email que existe queda en la auditoría de su empresa
            // (intento sobre esa cuenta); si no existe, solo lo ve el operador.
            app(AuditLogger::class)->record(
                'auth.login_failed',
                $user,
                $user?->email,
                metadata: ['email' => (string) $this->string('email')],
                tenantId: $user?->tenant_id,
            );

            throw ValidationException::withMessages([
                'email' => 'Las credenciales no son correctas.',
            ]);
        }

        return $user;
    }
}
