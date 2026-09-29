<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fija el tenant de la petición a partir del usuario autenticado (nunca de
 * una cabecera, subdominio o parámetro que controle el cliente). Va siempre
 * después de auth:sanctum y antes del route model binding.
 */
class ResolveTenant
{
    public function __construct(private readonly TenantService $tenancy)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ?? throw new AuthenticationException();
        $tenant = $user->tenant;

        // Tokens emitidos antes de desactivar la empresa dejan de servir.
        if (! $tenant->is_active) {
            return response()->json(['message' => 'La empresa está desactivada.'], 403);
        }

        $this->tenancy->set($tenant);

        // Aparece en cada línea de log y viaja a los jobs encolados en esta petición.
        Context::add('tenant_id', $tenant->id);

        return $next($request);
    }
}
