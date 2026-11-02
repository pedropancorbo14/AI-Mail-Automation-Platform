<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Security\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = $request->authenticate();

        if (! $user->tenant->is_active) {
            return $this->blocked($user, 'token');
        }

        if ($user->email_verified_at === null) {
            return $this->unverified();
        }

        $token = $user->createToken($request->input('device_name') ?? 'api');
        $this->audit->record('auth.login', $user, $user->email, metadata: ['method' => 'token', 'device_name' => $token->accessToken->name], actor: $user);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
        ]);
    }

    /**
     * Inicio de sesión del panel (misma web): cookie de sesión httpOnly en
     * vez de token, así un XSS no puede llevarse la credencial. Solo para
     * peticiones "stateful" de Sanctum (del dominio del panel); el resto de
     * clientes usa /auth/login.
     */
    public function session(LoginRequest $request): JsonResponse|UserResource
    {
        if (! $request->hasSession()) {
            return response()->json([
                'message' => 'Este inicio de sesión es solo para el panel. Las integraciones usan /api/v1/auth/login.',
            ], 400);
        }

        $user = $request->authenticate();

        if (! $user->tenant->is_active) {
            return $this->blocked($user, 'session');
        }

        if ($user->email_verified_at === null) {
            return $this->unverified();
        }

        Auth::guard('web')->login($user);
        // Nueva sesión tras autenticarse: evita la fijación de sesión.
        $request->session()->regenerate();
        $this->audit->record('auth.login', $user, $user->email, metadata: ['method' => 'session'], actor: $user);

        return new UserResource($user->load(['tenant', 'role.permissions']));
    }

    /**
     * Contraseña correcta pero email sin confirmar (alta desde la web). Solo
     * se dice tras comprobar la contraseña: no revela qué emails existen.
     */
    private function unverified(): JsonResponse
    {
        return response()->json([
            'message' => 'Confirma tu email antes de entrar: te enviamos un enlace al darte de alta.',
            'code' => 'email_unverified',
        ], 403);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load(['tenant', 'role.permissions']));
    }

    /**
     * Revoca solo el token usado en esta petición: el resto de tokens del
     * usuario (otros dispositivos, integraciones) siguen siendo válidos.
     */
    public function logout(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();
        $this->audit->record('auth.logout', $user, $user->email, metadata: ['method' => $token instanceof PersonalAccessToken ? 'token' : 'session']);

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        } elseif ($request->hasSession()) {
            // Autenticación SPA por cookie (panel de administración).
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    /** Credenciales correctas pero la empresa está desactivada. */
    private function blocked(User $user, string $method): JsonResponse
    {
        $this->audit->record('auth.login_blocked', $user, $user->email, metadata: ['method' => $method, 'reason' => 'tenant_inactive'], actor: $user);

        return response()->json(['message' => 'La empresa está desactivada.'], 403);
    }
}
