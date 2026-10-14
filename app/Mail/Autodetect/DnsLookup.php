<?php

namespace App\Mail\Autodetect;

/**
 * Consultas DNS de la autodetección (clase propia para poder simularla en
 * los tests: las funciones dns_* de PHP salen a internet).
 */
class DnsLookup
{
    /** @return list<string> Servidores de correo (MX) del dominio, por prioridad. */
    public function mx(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_MX) ?: [];
        usort($records, fn (array $a, array $b) => ($a['pri'] ?? 0) <=> ($b['pri'] ?? 0));

        return array_values(array_map(fn (array $record) => strtolower(rtrim($record['target'], '.')), $records));
    }

    /**
     * Registro SRV (RFC 6186): servidor y puerto, o null si no existe o es
     * "." (el dominio declara que no ofrece ese servicio).
     *
     * @return array{host: string, port: int}|null
     */
    public function srv(string $name): ?array
    {
        $records = @dns_get_record($name, DNS_SRV) ?: [];
        usort($records, fn (array $a, array $b) => ($a['pri'] ?? 0) <=> ($b['pri'] ?? 0));
        $first = $records[0] ?? null;

        if ($first === null || in_array(rtrim($first['target'] ?? '', '.'), ['', '.'], true)) {
            return null;
        }

        return ['host' => strtolower(rtrim($first['target'], '.')), 'port' => (int) $first['port']];
    }
}
