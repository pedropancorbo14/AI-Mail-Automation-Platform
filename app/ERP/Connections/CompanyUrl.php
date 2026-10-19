<?php

namespace App\ERP\Connections;

use App\Mail\Security\MailHostGuard;

/**
 * Anti-SSRF de las direcciones que escribe una empresa para conectar su
 * sistema (API estándar, tienda WooCommerce…): HTTPS (HTTP solo con hosts
 * privados permitidos, en local), sin usuario, contraseña, parámetros ni
 * "#", y el host no puede apuntar a la red interna (MailHostGuard). Las
 * peticiones se fijan a la IP comprobada (CURLOPT_RESOLVE: sin DNS rebinding).
 */
class CompanyUrl
{
    public function __construct(
        private readonly MailHostGuard $guard,
        private readonly bool $allowPrivateHosts = false,
    ) {
    }

    /** Motivo por el que la dirección no se puede usar, o null si vale. */
    public function reject(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return 'La dirección no es válida.';
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return 'La dirección no puede llevar usuario, contraseña, parámetros ni "#": la clave va aparte.';
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && ! ($scheme === 'http' && $this->allowPrivateHosts)) {
            return 'La dirección tiene que empezar por https://.';
        }

        return $this->guard->reject($parts['host']);
    }

    /** Opciones de cURL para fijar la conexión a la IP ya comprobada. */
    public function pinned(string $url): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $scheme = (string) parse_url($url, PHP_URL_SCHEME);
        $port = parse_url($url, PHP_URL_PORT) ?: ($scheme === 'http' ? 80 : 443);
        $ips = $this->guard->allowedIps($host);

        return $ips === [] ? [] : [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ips[0]}"]];
    }
}
