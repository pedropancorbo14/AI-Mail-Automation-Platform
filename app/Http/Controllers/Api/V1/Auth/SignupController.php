<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Tenancy\Signup\SignupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/**
 * Alta de empresas desde la web (Fase 17). Solo existe con
 * SAAS_SIGNUP_ENABLED; si no, 404 (el operador da de alta con tenant:create).
 */
class SignupController extends Controller
{
    private const string SENT = 'Si los datos son correctos, te hemos enviado un email para confirmar la cuenta. Revisa también la carpeta de spam.';

    public function __construct(private readonly SignupService $signup)
    {
    }

    /** Lo que necesita la pantalla de entrada para enseñar el enlace de alta. */
    public function show(): JsonResponse
    {
        return response()->json(['data' => [
            'enabled' => (bool) config('plans.signup_enabled'),
            'trial_days' => (int) config('plans.trial_days'),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(config('plans.signup_enabled'), 404);

        // Trampa para robots: un campo oculto que una persona deja vacío.
        if (filled($request->input('website'))) {
            return response()->json(['message' => self::SENT], 202);
        }

        $data = $request->validate([
            'company' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'accept_terms' => ['accepted'],
        ]);

        $this->signup->signup($data);

        return response()->json(['message' => self::SENT], 202);
    }

    public function resend(Request $request): JsonResponse
    {
        abort_unless(config('plans.signup_enabled'), 404);

        $this->signup->resend((string) $request->validate(['email' => ['required', 'email']])['email']);

        return response()->json(['message' => 'Si la cuenta está pendiente de confirmar, te hemos enviado otro enlace.'], 202);
    }

    /**
     * El enlace del email (firmado y con caducidad: middleware "signed").
     * Vuelve al panel con el resultado; no inicia sesión (entra con su contraseña).
     */
    public function verify(Request $request, string $user, string $hash): RedirectResponse
    {
        $login = rtrim((string) config('app.url'), '/').'/login';

        if (! $request->hasValidSignature()) {
            return redirect()->away("{$login}?verified=invalid");
        }

        $account = User::find($user);

        return redirect()->away($login.'?verified='.($account && $this->signup->verify($account, $hash) ? '1' : 'invalid'));
    }
}
