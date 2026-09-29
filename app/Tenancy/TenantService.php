<?php

namespace App\Tenancy;

use App\Models\Tenant;
use App\Tenancy\Exceptions\MissingTenantException;

/**
 * Tenant (empresa) en el que se está ejecutando la petición o el job actual.
 *
 * Registrado como "scoped" en AppServiceProvider: Laravel crea una instancia
 * nueva por petición HTTP y por job de cola, así que el tenant de un job
 * nunca se arrastra al siguiente.
 */
class TenantService
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function current(): ?Tenant
    {
        return $this->tenant;
    }

    /**
     * @throws MissingTenantException
     */
    public function currentOrFail(): Tenant
    {
        return $this->tenant ?? throw new MissingTenantException();
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function check(): bool
    {
        return $this->tenant !== null;
    }

    public function forget(): void
    {
        $this->tenant = null;
    }

    /**
     * Ejecuta $callback dentro de $tenant y restaura después el tenant que
     * hubiera (pensado para jobs, comandos y seeders).
     *
     * @template T
     *
     * @param  callable(Tenant): T  $callback
     * @return T
     */
    public function runAs(Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenant;
        $this->tenant = $tenant;

        try {
            return $callback($tenant);
        } finally {
            $this->tenant = $previous;
        }
    }
}
