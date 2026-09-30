<?php

namespace App\Mail\OAuth;

use App\Mail\Credentials\OAuthCredentials;
use App\Mail\Exceptions\MailAuthenticationException;
use App\Mail\Exceptions\MailConnectionException;
use App\Mail\Exceptions\MailProviderException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

/**
 * Llamadas al endpoint de tokens OAuth 2.0 (común a Microsoft y Google):
 * canje de código y renovación, con traducción de errores.
 */
class OAuthTokenEndpoint
{
    public function __construct(
        private readonly string $serviceName,
        private readonly string $tokenUrl,
        private readonly int $timeout = 15,
    ) {
    }

    /**
     * @param  array<string, string>  $params
     *
     * @throws MailAuthenticationException
     * @throws MailProviderException
     */
    public function request(#[SensitiveParameter] array $params, ?OAuthCredentials $previous = null): OAuthCredentials
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout($this->timeout)->post($this->tokenUrl, $params);
        } catch (ConnectionException $e) {
            throw new MailConnectionException("No se pudo conectar con {$this->serviceName}.", previous: $e);
        }

        if ($response->failed()) {
            // invalid_grant: código caducado/usado o refresh token revocado.
            if (in_array($response->json('error'), ['invalid_grant', 'invalid_client', 'unauthorized_client'], true)) {
                throw new MailAuthenticationException(
                    "{$this->serviceName} ha rechazado o revocado el acceso a la cuenta: vuelve a conectarla.",
                );
            }

            throw new MailProviderException(
                "{$this->serviceName} devolvió un error al obtener el token (HTTP {$response->status()}).",
            );
        }

        $scope = $response->json('scope');

        return new OAuthCredentials(
            accessToken: $response->json('access_token'),
            // Algunos proveedores no devuelven un refresh token nuevo al renovar: se conserva el anterior.
            refreshToken: $response->json('refresh_token') ?? $previous?->refreshToken,
            expiresAt: CarbonImmutable::now()->addSeconds((int) $response->json('expires_in', 3600)),
            scopes: $scope ? array_values(array_filter(explode(' ', $scope))) : ($previous?->scopes ?? []),
        );
    }
}
