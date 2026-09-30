<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MailConnectionResource;
use App\Mail\Enums\MailProviderType;
use App\Mail\Exceptions\MailProviderException;
use App\Mail\OAuth\MailOAuthService;
use App\Models\MailConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Conexión de cuentas Microsoft/Google con OAuth.
 *
 * start: autenticado (auth:sanctum + tenant), devuelve la URL a la que
 *        mandar al usuario.
 * callback: PÚBLICO (vuelve el navegador desde Microsoft/Google sin nuestro
 *           token); la empresa y el usuario salen del state.
 */
class MailOAuthController extends Controller
{
    public function start(Request $request, string $provider, MailOAuthService $oauth): JsonResponse
    {
        Gate::authorize('create', MailConnection::class);

        $type = self::oauthProvider($provider);
        $data = $request->validate(['name' => ['nullable', 'string', 'max:255']]);

        try {
            $url = $oauth->start($request->user(), $type, $data['name'] ?? null);
        } catch (MailProviderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['authorization_url' => $url]);
    }

    public function callback(Request $request, string $provider, MailOAuthService $oauth): JsonResponse|RedirectResponse
    {
        $type = self::oauthProvider($provider);

        // El usuario canceló o el proveedor devolvió un error.
        if ($request->filled('error')) {
            return $this->finish(error: 'No se ha concedido el acceso a la cuenta de correo.');
        }

        if (! $request->filled('state') || ! $request->filled('code')) {
            return $this->finish(error: 'Faltan parámetros en la respuesta de autorización.');
        }

        try {
            $connection = $oauth->complete($type, (string) $request->query('state'), (string) $request->query('code'));
        } catch (MailProviderException $e) {
            Log::warning('Fallo al completar la conexión OAuth de correo', ['provider' => $type->value, 'error' => $e->getMessage()]);

            return $this->finish(error: $e->getMessage());
        }

        return $this->finish(connection: $connection);
    }

    /** Solo proveedores que se conectan con OAuth; el resto es 404. */
    private static function oauthProvider(string $provider): MailProviderType
    {
        $type = MailProviderType::tryFrom($provider);

        abort_unless($type?->usesOAuth(), 404);

        return $type;
    }

    /**
     * Con MAIL_CONNECTIONS_OAUTH_REDIRECT_TO se vuelve al panel con el
     * resultado en la URL; si no, se responde JSON.
     */
    private function finish(?MailConnection $connection = null, ?string $error = null): JsonResponse|RedirectResponse
    {
        if ($redirect = config('mail_connections.oauth_redirect_to')) {
            $query = $connection
                ? ['status' => 'connected', 'connection_id' => $connection->id]
                : ['status' => 'error', 'message' => $error];

            return redirect()->away($redirect . (str_contains($redirect, '?') ? '&' : '?') . http_build_query($query));
        }

        return $connection
            ? response()->json(['data' => new MailConnectionResource($connection)], 201)
            : response()->json(['message' => $error], 422);
    }
}
