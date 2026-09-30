<?php

namespace App\Mail\Providers\Google;

use App\Mail\Credentials\OAuthCredentials;
use App\Mail\Enums\MailProviderType;
use App\Mail\Exceptions\MailProviderException;
use App\Mail\OAuth\Contracts\OAuthClient;
use App\Mail\OAuth\OAuthAccount;
use App\Mail\OAuth\OAuthTokenEndpoint;
use App\Mail\OAuth\StaticTokenSource;
use SensitiveParameter;

/**
 * OAuth 2.0 de Google para la Gmail API.
 *
 * Producción: gmail.readonly es un scope "restringido": para cuentas de
 * otras organizaciones Google exige verificar la app (en modo pruebas solo
 * funciona con los usuarios de prueba registrados en Google Cloud).
 */
class GoogleOAuthClient implements OAuthClient
{
    public const array SCOPES = [GmailProvider::SCOPE_READ, GmailProvider::SCOPE_SEND];

    private const string AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const string TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function __construct(
        private readonly ?string $clientId,
        private readonly ?string $clientSecret,
        private readonly string $redirectUri,
        private readonly int $timeout = 15,
    ) {
    }

    public function provider(): MailProviderType
    {
        return MailProviderType::Google;
    }

    public function configured(): bool
    {
        return filled($this->clientId) && filled($this->clientSecret);
    }

    public function authorizationUrl(string $state, string $codeChallenge): string
    {
        return self::AUTHORIZE_URL . '?' . http_build_query([
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri,
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            // Sin estos dos Google no devuelve refresh token al reconectar
            // y el acceso dejaría de funcionar al caducar el access token (1 h).
            'access_type' => 'offline',
            'prompt' => 'consent select_account',
        ], encoding_type: PHP_QUERY_RFC3986);
    }

    public function exchangeCode(#[SensitiveParameter] string $code, #[SensitiveParameter] string $codeVerifier): OAuthAccount
    {
        $credentials = $this->tokens()->request([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => $codeVerifier,
        ]);

        // El perfil de Gmail da la dirección sin pedir scopes de identidad adicionales.
        $profile = (new GmailClient(new StaticTokenSource($credentials), $this->timeout))->get('/profile');

        if (empty($profile['emailAddress'])) {
            throw new MailProviderException('Google no ha devuelto la dirección de correo de la cuenta.');
        }

        return new OAuthAccount(strtolower($profile['emailAddress']), null, $credentials);
    }

    public function refresh(OAuthCredentials $credentials): OAuthCredentials
    {
        // Google no devuelve un refresh token nuevo al renovar: OAuthTokenEndpoint conserva el anterior.
        return $this->tokens()->request([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => (string) $credentials->refreshToken,
        ], previous: $credentials);
    }

    private function tokens(): OAuthTokenEndpoint
    {
        return new OAuthTokenEndpoint('Google', self::TOKEN_URL, $this->timeout);
    }
}
