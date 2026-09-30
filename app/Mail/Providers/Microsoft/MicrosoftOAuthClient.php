<?php

namespace App\Mail\Providers\Microsoft;

use App\Mail\Credentials\OAuthCredentials;
use App\Mail\Enums\MailProviderType;
use App\Mail\Exceptions\MailProviderException;
use App\Mail\OAuth\Contracts\OAuthClient;
use App\Mail\OAuth\OAuthAccount;
use App\Mail\OAuth\OAuthTokenEndpoint;
use App\Mail\OAuth\StaticTokenSource;
use SensitiveParameter;

/**
 * OAuth 2.0 de Microsoft identity platform (v2.0) para Microsoft Graph.
 */
class MicrosoftOAuthClient implements OAuthClient
{
    /**
     * Mínimos necesarios: leer/crear borradores (responder en el hilo),
     * enviar, identificar la cuenta y renovar sin volver a pedir permiso.
     */
    public const array SCOPES = ['offline_access', 'User.Read', 'Mail.ReadWrite', 'Mail.Send'];

    public function __construct(
        private readonly ?string $clientId,
        private readonly ?string $clientSecret,
        private readonly string $tenant,
        private readonly string $redirectUri,
        private readonly int $timeout = 15,
    ) {
    }

    public function provider(): MailProviderType
    {
        return MailProviderType::Microsoft;
    }

    public function configured(): bool
    {
        return filled($this->clientId) && filled($this->clientSecret);
    }

    public function authorizationUrl(string $state, string $codeChallenge): string
    {
        return $this->endpoint('authorize') . '?' . http_build_query([
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri,
            'response_mode' => 'query',
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            // Muestra siempre el selector de cuenta (evita conectar sin querer la sesión abierta).
            'prompt' => 'select_account',
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
            'scope' => implode(' ', self::SCOPES),
        ]);

        $me = (new GraphClient(new StaticTokenSource($credentials), $this->timeout))
            ->get('/me', ['$select' => 'displayName,mail,userPrincipalName']);

        // "mail" puede venir vacío en cuentas personales: userPrincipalName es el login.
        $email = $me['mail'] ?? $me['userPrincipalName'] ?? null;

        if (! $email) {
            throw new MailProviderException('Microsoft no ha devuelto la dirección de correo de la cuenta.');
        }

        return new OAuthAccount(strtolower($email), $me['displayName'] ?? null, $credentials);
    }

    public function refresh(OAuthCredentials $credentials): OAuthCredentials
    {
        return $this->tokens()->request([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => (string) $credentials->refreshToken,
            'scope' => implode(' ', self::SCOPES),
        ], previous: $credentials);
    }

    private function tokens(): OAuthTokenEndpoint
    {
        return new OAuthTokenEndpoint('Microsoft', $this->endpoint('token'), $this->timeout);
    }

    private function endpoint(string $action): string
    {
        return "https://login.microsoftonline.com/{$this->tenant}/oauth2/v2.0/{$action}";
    }
}
