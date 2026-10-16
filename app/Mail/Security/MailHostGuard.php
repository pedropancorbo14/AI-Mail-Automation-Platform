<?php

namespace App\Mail\Security;

use App\Mail\Exceptions\MailConnectionException;

/**
 * Anti-SSRF: impide que un cliente haga que el servidor se conecte a la
 * red interna (Redis, Postgres, otros contenedores, metadatos de la nube)
 * usando una conexión IMAP/SMTP con un host elegido por él.
 *
 * Se comprueba al validar la petición y otra vez justo antes de conectar
 * (el DNS puede cambiar entre ambos momentos: DNS rebinding).
 */
class MailHostGuard
{
    public function __construct(
        private readonly bool $allowPrivateHosts,
    ) {
    }

    /**
     * @throws MailConnectionException
     */
    public function assertAllowed(string $host): void
    {
        if ($reason = $this->reject($host)) {
            throw new MailConnectionException($reason);
        }
    }

    /** Motivo del rechazo, o null si el host está permitido. */
    public function reject(string $host): ?string
    {
        $ips = $this->resolve($host);

        // Nunca, ni con hosts privados permitidos en local (Fase 12): ningún
        // servidor de correo vive en una dirección de enlace local (ahí están
        // los metadatos de la nube, con credenciales) ni en 0.0.0.0/8.
        foreach ($ips as $ip) {
            if (self::isAlwaysBlocked($ip)) {
                return "El servidor {$host} apunta a una dirección interna no permitida.";
            }
        }

        if ($this->allowPrivateHosts) {
            return null;
        }

        if ($ips === []) {
            return "No se puede resolver el servidor {$host}.";
        }

        foreach ($ips as $ip) {
            if (! filter_var(self::unmapped($ip), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return "El servidor {$host} apunta a una dirección interna no permitida.";
            }
        }

        return null;
    }

    /** 169.254.0.0/16 y fe80::/10 (enlace local, metadatos de la nube) y 0.0.0.0/8. */
    private static function isAlwaysBlocked(string $ip): bool
    {
        $ip = self::unmapped($ip);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return str_starts_with($ip, '169.254.') || str_starts_with($ip, '0.');
        }

        $bytes = @inet_pton($ip);

        return $bytes !== false && strlen($bytes) === 16 && ord($bytes[0]) === 0xFE && (ord($bytes[1]) & 0xC0) === 0x80;
    }

    /** "::ffff:169.254.169.254" (IPv4 escrita como IPv6) → "169.254.169.254". */
    private static function unmapped(string $ip): string
    {
        $bytes = @inet_pton($ip);

        if ($bytes !== false && strlen($bytes) === 16 && str_starts_with($bytes, str_repeat("\0", 10) . "\xFF\xFF")) {
            return (string) inet_ntop(substr($bytes, 12));
        }

        return $ip;
    }

    /**
     * IPs del host ya comprobadas, para fijar la conexión a ellas (CURLOPT_RESOLVE)
     * y que el DNS no pueda cambiar entre la comprobación y la petición.
     * Vacío si no resuelve o si alguna IP es interna.
     *
     * @return list<string>
     */
    public function allowedIps(string $host): array
    {
        if ($this->reject($host) !== null) {
            return [];
        }

        return $this->resolve($host);
    }

    /** @return list<string> */
    private function resolve(string $host): array
    {
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            $ips[] = $record['ipv6'];
        }

        return array_values(array_unique($ips));
    }
}
