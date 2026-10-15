<?php

namespace App\Http\Middleware;

use App\Observability\Metrics;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cuenta y cronometra cada petición HTTP. La etiqueta "route" es la plantilla
 * de la ruta (api/v1/emails/{email}), nunca la URL real: con ids o rutas
 * inventadas por un escáner habría una serie por URL. Sin ruta → "unmatched".
 * Las lecturas de /metrics no se cuentan (serían ruido cada 15 s).
 */
class RecordHttpMetrics
{
    public function __construct(private readonly Metrics $metrics)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);
        $response = $next($request);

        $route = $request->route()?->uri() ?? 'unmatched';

        if ($route !== 'metrics') {
            $labels = ['method' => $request->method(), 'route' => $route];
            $this->metrics->increment('http_requests_total', $labels + ['status' => $response->getStatusCode()]);
            $this->metrics->observe('http_request_duration_seconds', (hrtime(true) - $startedAt) / 1e9, $labels);
        }

        return $response;
    }
}
