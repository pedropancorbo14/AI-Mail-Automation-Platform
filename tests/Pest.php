<?php

use App\Models\Tenant;
use App\Tenancy\TenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class)->in('Feature');

/**
 * Ejecuta $callback dentro de $tenant: necesario para crear o consultar
 * modelos con BelongsToTenant fuera de una petición HTTP.
 *
 * @template T
 *
 * @param  callable(Tenant): T  $callback
 * @return T
 */
function inTenant(Tenant $tenant, callable $callback): mixed
{
    return app(TenantService::class)->runAs($tenant, $callback);
}
