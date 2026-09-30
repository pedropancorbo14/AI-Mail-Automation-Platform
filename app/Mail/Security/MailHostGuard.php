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
        if ($this->allowPrivateHosts) {
            return null;
        }

        $ips = $this->resolve($host);

        if ($ips === []) {
            return "No se puede resolver el servidor {$host}.";
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return "El servidor {$host} apunta a una dirección interna no permitida.";
            }
        }

        return null;
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
