<?php

namespace App\Mail\OAuth;

use App\Mail\Credentials\OAuthCredentials;
use App\Mail\Exceptions\MailAuthenticationException;
use App\Mail\OAuth\Contracts\TokenSource;

/** Usa el token guardado tal cual, sin renovarlo (tests y usos puntuales). */
class StaticTokenSource implements TokenSource
{
    public function __construct(
        private readonly OAuthCredentials $credentials,
    ) {
    }

    public function accessToken(): string
    {
        if ($this->credentials->isExpired(0)) {
            throw new MailAuthenticationException('El acceso a la cuenta ha caducado: hay que volver a conectarla.');
        }

        return $this->credentials->accessToken;
    }

    public function refresh(): string
    {
        throw new MailAuthenticationException('El acceso a la cuenta ha caducado: hay que volver a conectarla.');
    }
}
