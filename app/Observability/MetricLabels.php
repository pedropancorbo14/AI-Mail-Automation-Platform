<?php

namespace App\Observability;

use Illuminate\Support\Facades\DB;

/**
 * Valores de etiquetas que salen de la base de datos (slug de la empresa,
 * tipo de buzón), con caché por proceso: no cambian (el slug no se puede
 * editar y un buzón no cambia de proveedor). Consultas directas, sin el
 * scope de tenant: solo se lee el dato del propio registro.
 */
class MetricLabels
{
    /** @var array<int, string> */
    private array $tenants = [];

    /** @var array<int, string> */
    private array $providers = [];

    public function tenant(?int $tenantId): string
    {
        if ($tenantId === null) {
            return 'none';
        }

        return $this->tenants[$tenantId] ??= (string) (DB::table('tenants')->where('id', $tenantId)->value('slug') ?? $tenantId);
    }

    public function mailProvider(?int $connectionId): string
    {
        if ($connectionId === null) {
            return 'none';
        }

        return $this->providers[$connectionId] ??= (string) (DB::table('mail_connections')->where('id', $connectionId)->value('provider') ?? 'none');
    }
}
