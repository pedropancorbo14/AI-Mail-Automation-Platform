<?php

namespace App\Tenancy\Concerns;

use App\Models\Tenant;
use App\Tenancy\Exceptions\MissingTenantException;
use App\Tenancy\Scopes\TenantScope;
use App\Tenancy\TenantService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Para todo modelo con columna tenant_id (mail_connections, emails,
 * ai_requests...): filtra las consultas por el tenant actual y asigna
 * tenant_id al crear. No se usa en User: Sanctum resuelve el usuario antes
 * de que exista tenant en contexto (los usuarios se protegen con policies).
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (Model $model) {
            $tenant = app(TenantService::class)->currentOrFail();

            $model->tenant_id ??= $tenant->id;

            if ($model->tenant_id !== $tenant->id) {
                throw new MissingTenantException('No se pueden crear datos en otro tenant.');
            }
        });
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
