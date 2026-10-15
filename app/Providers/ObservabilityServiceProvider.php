<?php

namespace App\Providers;

use App\Models\Email;
use App\Models\EmailReply;
use App\Models\EmailStatusChange;
use App\Models\MailSyncState;
use App\Observability\MetricLabels;
use App\Observability\Metrics;
use App\Observability\Observers\EmailMetricsObserver;
use App\Observability\Observers\EmailReplyMetricsObserver;
use App\Observability\Observers\EmailStatusChangeMetricsObserver;
use App\Observability\Observers\MailSyncMetricsObserver;
use Illuminate\Support\ServiceProvider;

/**
 * Observabilidad (Fase 11): métricas de Prometheus. Un solo registro de
 * métricas por proceso (la conexión a Redis se abre al primer uso).
 *
 * Las métricas de negocio salen de los mismos registros que ya sirven de
 * auditoría (emails, historial de estados, respuestas, sincronizaciones...)
 * mediante observers: el código de negocio no sabe nada de Prometheus.
 */
class ObservabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Metrics::class, fn () => Metrics::fromConfig());
        $this->app->singleton(MetricLabels::class);
    }

    public function boot(): void
    {
        Email::observe(EmailMetricsObserver::class);
        EmailStatusChange::observe(EmailStatusChangeMetricsObserver::class);
        EmailReply::observe(EmailReplyMetricsObserver::class);
        MailSyncState::observe(MailSyncMetricsObserver::class);
    }
}
