<?php

namespace App\Mail\OAuth\Contracts;

use App\Mail\Exceptions\MailAuthenticationException;

/**
 * Fuente del access token OAuth de una conexión (Microsoft Graph, Gmail).
 */
interface TokenSource
{
    /**
     * Token válido para usar ahora (renovándolo antes si ha caducado).
     *
     * @throws MailAuthenticationException
     */
    public function accessToken(): string;

    /**
     * Fuerza la renovación (p. ej. la API respondió 401 con un token que
     * aún no había caducado) y devuelve el token nuevo.
     *
     * @throws MailAuthenticationException Si no se puede renovar: hay que reconectar la cuenta.
     */
    public function refresh(): string;
}
