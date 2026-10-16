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

        // El slug, el estado (is_active) y el presupuesto de IA no los cambia la propia empresa.
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            // Días que se conservan los emails; null = los de la plataforma.
            'email_retention_days' => ['sometimes', 'nullable', 'integer', 'min:' . config('retention.min_email_days'), 'max:' . config('retention.max_email_days')],
        ]);
        $tenant->forceFill($data)->save();

        if ($changes = AuditLogger::changesOf($tenant, ['name', 'email_retention_days'])) {
            $this->audit->record('tenant.updated', $tenant, $tenant->name, $changes);
        }

        return new TenantResource($tenant);
    }
}
