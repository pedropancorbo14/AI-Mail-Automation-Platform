<?php

namespace App\Tenancy\Scopes;

use App\Tenancy\TenantService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limita cualquier consulta del modelo al tenant actual. Para consultas
 * globales deliberadas (administración de la plataforma, mantenimiento):
 * Model::withoutGlobalScope(TenantScope::class).
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = app(TenantService::class)->currentOrFail()->id;

        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
    }
}
