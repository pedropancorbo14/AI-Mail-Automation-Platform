<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\ListAuditLogsRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Permission;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Registro de auditoría de la empresa (solo lectura: no existe ninguna ruta
 * para cambiarlo). TenantScope lo limita a la empresa del usuario.
 */
class AuditLogController extends Controller
{
    /** Categorías de acción (lo que va antes del punto). */
    public const array CATEGORIES = ['auth', 'user', 'tenant', 'mail_connection', 'automation', 'document', 'email', 'retention', 'orders'];

    public function index(ListAuditLogsRequest $request): AnonymousResourceCollection
    {
        Gate::authorize(Permission::AUDIT_VIEW);

        $logs = AuditLog::query()
            ->with('user:id,name')
            ->when($request->validated('category'), fn ($q, $category) => $q->where('action', 'like', $category . '.%'))
            ->when($request->validated('user'), fn ($q, $user) => $q->where('user_id', $user))
            ->when($request->validated('from'), fn ($q, $from) => $q->where('created_at', '>=', $from))
            ->when($request->validated('to'), fn ($q, $to) => $q->where('created_at', '<', now()->parse($to)->addDay()->startOfDay()))
            ->latest()->latest('id');

        return AuditLogResource::collection($logs->paginate($request->integer('per_page', 50))->withQueryString());
    }
}
