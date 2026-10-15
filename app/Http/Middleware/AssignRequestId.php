<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identificador de cada petición en el Context: aparece en todas sus líneas
 * de log (y en las de los trabajos que encole, porque el Context viaja con
 * ellos) y se devuelve en la cabecera X-Request-Id. Con él se encuentra en
 * Loki todo lo que pasó por una petición concreta.
 *
 * Se acepta el que envíe un proxy por delante si tiene un formato seguro
 * (no se mete texto arbitrario del cliente en los logs).
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get('X-Request-Id');
        $id = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $incoming) ? $incoming : (string) Str::uuid();

        Context::add('request_id', $id);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
