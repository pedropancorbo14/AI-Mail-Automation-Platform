<?php

namespace App\Http\Controllers;

use App\Observability\Metrics;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /metrics: métricas en formato de Prometheus. Solo con el token de
 * METRICS_TOKEN ("Authorization: Bearer ..."): revelan volumen y costes de
 * cada empresa. Sin token configurado, o con uno incorrecto, responde 404
 * (no se anuncia que el endpoint existe).
 */
class MetricsController extends Controller
{
    public function __invoke(Request $request, Metrics $metrics): Response
    {
        $token = (string) config('metrics.token');

        abort_unless(
            config('metrics.enabled') && $token !== '' && hash_equals($token, (string) $request->bearerToken()),
            404,
        );

        return response($metrics->render(), 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']);
    }
}
