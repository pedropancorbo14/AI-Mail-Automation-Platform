<?php

namespace App\Providers;

use App\Observability\Metrics;
use Illuminate\Support\ServiceProvider;

/**
 * Observabilidad (Fase 11): métricas de Prometheus. Un solo registro de
 * métricas por proceso (la conexión a Redis se abre al primer uso).
 */
class ObservabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Metrics::class, fn () => Metrics::fromConfig());
    }
}
