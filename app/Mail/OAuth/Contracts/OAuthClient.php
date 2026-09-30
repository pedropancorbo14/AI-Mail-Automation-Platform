<?php

namespace App\Mail\OAuth\Contracts;

use App\Mail\Credentials\OAuthCredentials;
use App\Mail\Enums\MailProviderType;
use App\Mail\Exceptions\MailAuthenticationException;
use App\Mail\Exceptions\MailProviderException;
use App\Mail\OAuth\OAuthAccount;

/**
 * Flujo OAuth 2.0 (authorization code + PKCE) de un proveedor de correo.
 */
interface OAuthClient
{
    public function provider(): MailProviderType;

    /** La app está registrada (client id/secret configurados). */
    public function configured(): bool;

    /** URL a la que se envía al usuario para autorizar el acceso a su buzón. */
    public function authorizationUrl(string $state, string $codeChallenge): string;

    /**
     * Canjea el código de autorización por tokens e identifica la cuenta.
     *
     * @throws MailAuthenticationException
     * @throws MailProviderException
     */
    public function exchangeCode(string $code, string $codeVerifier): OAuthAccount;

    /**
     * @throws MailAuthenticationException El refresh token ya no es válido: hay que reconectar.
     * @throws MailProviderException
     */
    public function refresh(OAuthCredentials $credentials): OAuthCredentials;
}
