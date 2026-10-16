<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Models\User;
use App\Security\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * La cuenta del propio usuario: cambiar la contraseña y cerrar la sesión
 * en los demás dispositivos.
 *
 * Sesiones del panel: el middleware AuthenticateSession de Sanctum guarda en
 * cada sesión una huella de la contraseña y cierra las que no coinciden.
 * Basta con que cambie el hash guardado (contraseña nueva, o la misma
 * cifrada otra vez) para que caigan todas menos la actual, que guarda la
 * huella nueva al terminar la petición. Tokens de API: se revocan todos
 * menos el de esta petición.
 */
class AccountController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $this->verifiedUser($request, 'current_password');

        $user->forceFill(['password' => $request->validated('password')])->save();
        $revoked = $this->revokeOtherTokens($request, $user);
        $this->audit->record('auth.password_changed', $user, $user->email, metadata: ['revoked_count' => $revoked]);

        return response()->json(['message' => 'Contraseña cambiada. Se ha cerrado la sesión en los demás dispositivos.']);
    }

    public function logoutOtherDevices(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string']]);
        $user = $this->verifiedUser($request, 'password');

        // Misma contraseña con otra sal: cambia el hash y las demás sesiones dejan de valer.
        $user->forceFill(['password' => $request->input('password')])->save();
        $revoked = $this->revokeOtherTokens($request, $user);
        $this->audit->record('auth.logout_other_devices', $user, $user->email, metadata: ['revoked_count' => $revoked]);

        return response()->json(['message' => 'Se ha cerrado la sesión en los demás dispositivos.']);
    }

    /** Pedir la contraseña actual: una sesión robada no basta para quedarse con la cuenta. */
    private function verifiedUser(Request $request, string $field): User
    {
        /** @var User $user */
        $user = $request->user();

        if (! Hash::check((string) $request->input($field), $user->password)) {
            throw ValidationException::withMessages([$field => 'La contraseña actual no es correcta.']);
        }

        return $user;
    }

    private function revokeOtherTokens(Request $request, User $user): int
    {
        $current = $user->currentAccessToken();

        if ($request->hasSession()) {
            // Nuevo id de sesión tras un cambio de credenciales.
            $request->session()->regenerate();
        }

        return $user->tokens()
            ->when($current instanceof PersonalAccessToken, fn ($query) => $query->whereKeyNot($current->getKey()))
            ->delete();
    }
}
