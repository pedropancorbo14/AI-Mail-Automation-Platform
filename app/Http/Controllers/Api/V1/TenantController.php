<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TenantResource;
use App\Security\Audit\AuditLogger;
use App\Tenancy\TenantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * La empresa del usuario autenticado. Sin id en la URL: no existe ninguna
 * ruta con la que intentar leer o editar otra empresa.
 */
class TenantController extends Controller
{
    public function __construct(
        private readonly TenantService $tenancy,
        private readonly AuditLogger $audit,
    ) {
    }

    public function show(): TenantResource
    {
        $tenant = $this->tenancy->currentOrFail();
        Gate::authorize('view', $tenant);

        return new TenantResource($tenant);
    }

    public function update(Request $request): TenantResource
    {
        $tenant = $this->tenancy->currentOrFail();
        Gate::authorize('update', $tenant);

        // El slug y el estado (is_active) no los cambia la propia empresa.
        $tenant->update($request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]));

        if ($changes = AuditLogger::changesOf($tenant, ['name'])) {
            $this->audit->record('tenant.updated', $tenant, $tenant->name, $changes);
        }

        return new TenantResource($tenant);
    }
}
