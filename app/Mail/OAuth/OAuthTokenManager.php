<?php

namespace App\Mail\OAuth;

use App\Mail\Credentials\OAuthCredentials;
use App\Mail\Enums\MailConnectionStatus;
use App\Mail\Exceptions\MailAuthenticationException;
use App\Mail\OAuth\Contracts\OAuthClient;
use App\Mail\OAuth\Contracts\TokenSource;
use App\Models\MailConnection;
use Illuminate\Support\Facades\Cache;

/**
 * Token de una conexión OAuth que se renueva solo cuando caduca y guarda
 * los tokens nuevos (cifrados) en la conexión.
 *
 * Lock por conexión: Microsoft rota el refresh token en cada renovación;
 * si dos workers renovaran a la vez, uno usaría un refresh token ya
 * invalidado. Con el lock, el segundo espera y reutiliza el token nuevo.
 */
class OAuthTokenManager implements TokenSource
{
    public function __construct(
        private readonly MailConnection $connection,
        private readonly OAuthClient $client,
    ) {
    }

    public function accessToken(): string
    {
        $credentials = $this->credentials();

        return $credentials->isExpired() ? $this->refresh() : $credentials->accessToken;
    }

    public function refresh(): string
    {
        $stale = $this->credentials()->accessToken;

        return Cache::lock("mail-oauth-refresh:{$this->connection->id}", 30)->block(15, function () use ($stale) {
            // Otro proceso puede haberlo renovado mientras esperábamos el lock.
            $current = $this->reload();

            if ($current->accessToken !== $stale && ! $current->isExpired()) {
                return $current->accessToken;
            }

            if (! $current->refreshToken) {
                $this->markNeedsReconnect('La cuenta no tiene token de renovación: vuelve a conectarla.');
            }

            try {
                $renewed = $this->client->refresh($current);
            } catch (MailAuthenticationException $e) {
                $this->markNeedsReconnect($e->getMessage());
            }

            $this->connection->credentials = $renewed;
            $this->connection->save();

            return $renewed->accessToken;
        });
    }

    private function credentials(): OAuthCredentials
    {
        return $this->connection->credentials;
    }

    /** Credenciales actuales en BD (sin el scope de tenant: la conexión ya está cargada y es la nuestra). */
    private function reload(): OAuthCredentials
    {
        $fresh = MailConnection::withoutGlobalScopes()->findOrFail($this->connection->id);

        $this->connection->forceFill(['credentials' => $fresh->credentials])->syncOriginalAttribute('credentials');

        return $this->connection->credentials;
    }

    /**
     * @throws MailAuthenticationException
     */
    private function markNeedsReconnect(string $message): never
    {
        $this->connection->status = MailConnectionStatus::Failing;
        $this->connection->last_error = $message;
        $this->connection->save();

        throw new MailAuthenticationException($message);
    }
}
