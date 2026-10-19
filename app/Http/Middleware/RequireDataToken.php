<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API interna de datos para el servidor MCP de ai-service (Fase 14): exige
 * "Authorization: Bearer <integrations.data_token>". Sin token configurado, o
 * con uno incorrecto, 404: no se anuncia que la ruta existe (como /metrics).
 */
class RequireDataToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('integrations.data_token');

        if ($token === '' || ! hash_equals($token, (string) $request->bearerToken())) {
            return response()->json(['message' => 'Recurso no encontrado.'], 404);
        }

        return $next($request);
    }
}
