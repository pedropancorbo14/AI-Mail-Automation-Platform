<?php

namespace App\Mail\OAuth;

use App\Billing\PlanGuard;
use App\Billing\PlanLimit;
use App\Billing\PlanLimitException;
use App\Mail\Enums\MailConnectionStatus;
use App\Mail\Enums\MailProviderType;
use App\Mail\Exceptions\MailProviderException;
use App\Models\MailConnection;
use App\Models\Permission;
use App\Models\User;
use App\Security\Audit\AuditLogger;
use App\Tenancy\TenantService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Conectar cuentas de correo con OAuth (Microsoft, Google).
 *
 * start(): genera state (anti-CSRF, un solo uso) y verificador PKCE, y los
 * guarda en caché con la empresa y el usuario que inician el flujo.
 * complete(): el callback llega del navegador SIN nuestro token; la empresa
 * y el usuario salen del state, nunca de la petición.
 */
class MailOAuthService
{
    public function __construct(
        private readonly OAuthClients $clients,
        private readonly TenantService $tenancy,
        private readonly AuditLogger $audit,
        private readonly PlanGuard $plans,
    ) {
    }

    /**
     * @throws MailProviderException
     */
    public function start(User $user, MailProviderType $provider, ?string $name = null): string
    {
        $client = $this->clients->for($provider);

        if (! $client->configured()) {
            throw new MailProviderException("La conexión con {$provider->value} no está configurada en este servidor.");
        }

        $state = Str::random(40);
        $verifier = Str::random(64);

        Cache::put(self::cacheKey($state), [
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'provider' => $provider->value,
            'name' => $name,
            'verifier' => $verifier,
        ], now()->addMinutes(config('mail_connections.oauth_state_ttl')));

        return $client->authorizationUrl($state, self::codeChallenge($verifier));
    }

    /**
     * @throws MailProviderException
     */
    public function complete(MailProviderType $provider, string $state, string $code): MailConnection
    {
        // pull: el state solo vale una vez (evita reutilizar un callback).
        $flow = Cache::pull(self::cacheKey($state));

        if (! $flow || $flow['provider'] !== $provider->value) {
            throw new MailProviderException('La autorización ha caducado o no es válida: vuelve a iniciar la conexión.');
        }

        // Se revalida: el usuario podría haber perdido el permiso o la empresa estar desactivada.
        $user = User::with('tenant')->find($flow['user_id']);

        if (! $user || $user->tenant_id !== $flow['tenant_id'] || ! $user->tenant->is_active
            || ! $user->hasPermission(Permission::MAIL_CONNECTIONS_MANAGE)) {
            throw new MailProviderException('El usuario que inició la conexión ya no puede gestionar conexiones de correo.');
        }

        $account = $this->clients->for($provider)->exchangeCode($code, $flow['verifier']);

        return $this->tenancy->runAs($user->tenant, function () use ($account, $provider, $flow, $user) {
            $connection = MailConnection::where('email', $account->email)->first();

            if ($connection && $connection->provider !== $provider) {
                throw new MailProviderException(
                    "La cuenta {$account->email} ya está conectada como {$connection->provider->value}.",
                );
            }

            // Una cuenta nueva (o desactivada) ocupa un hueco del plan; reconectar una activa, no.
            if ($connection === null || $connection->status === MailConnectionStatus::Disabled) {
                try {
                    $this->plans->ensureCanAdd($user->tenant, PlanLimit::Mailboxes);
                } catch (PlanLimitException $e) {
                    throw new MailProviderException($e->getMessage());
                }
            }

            // Reconectar una cuenta existente actualiza sus tokens en vez de duplicarla.
            $connection ??= new MailConnection([
                'name' => $flow['name'] ?: ($account->name ?: $account->email),
                'email' => $account->email,
            ]);

            $connection->provider = $provider;
            $connection->credentials = $account->credentials;
            $connection->created_by ??= $user->id;
            $connection->status = MailConnectionStatus::Pending;
            $connection->last_error = null;
            $reconnected = $connection->exists;
            $connection->save();

            // El callback llega sin sesión: el actor es quien inició el flujo (sale del state).
            $this->audit->record('mail_connection.oauth_connected', $connection, $connection->name, metadata: [
                'email' => $connection->email,
                'provider' => $provider->value,
                'reconnected' => $reconnected,
            ], actor: $user);

            return $connection;
        });
    }

    private static function cacheKey(string $state): string
    {
        return 'mail-oauth-state:' . hash('sha256', $state);
    }

    /** PKCE S256: base64url(sha256(verifier)). */
    public static function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
