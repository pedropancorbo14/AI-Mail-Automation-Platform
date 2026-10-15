<?php

namespace App\Observability;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;
use Throwable;

/**
 * Métricas de ESTADO ACTUAL, calculadas en cada lectura de /metrics y no
 * guardadas (si se guardaran, una etiqueta que desaparece —una empresa sin
 * emails en revisión— se quedaría con su último valor). Son consultas
 * agregadas sobre columnas indexadas (tenant + status): baratas cada 15 s.
 */
class StateMetrics
{
    public function __construct(
        private readonly MetricLabels $labels,
        private readonly string $namespace = 'aimail',
    ) {
    }

    public function render(): string
    {
        $registry = new CollectorRegistry(new InMemory(), registerDefaultMetrics: false);

        $this->emails($registry);
        $this->mailboxes($registry);
        $this->documents($registry);
        $this->queues($registry);

        return (new RenderTextFormat())->render($registry->getMetricFamilySamples());
    }

    /** Emails por estado ahora mismo (p. ej. cuántos esperan a una persona). */
    private function emails(CollectorRegistry $registry): void
    {
        $gauge = $registry->registerGauge($this->namespace, 'emails_current', 'Emails por estado en este momento.', ['tenant', 'status']);

        DB::table('emails')->select('tenant_id', 'status', DB::raw('count(*) as total'))
            ->groupBy('tenant_id', 'status')->get()
            ->each(fn ($row) => $gauge->set($row->total, [$this->labels->tenant($row->tenant_id), $row->status]));
    }

    /** Buzones por estado y, de cada buzón activo, su sincronización. */
    private function mailboxes(CollectorRegistry $registry): void
    {
        $connections = $registry->registerGauge($this->namespace, 'mail_connections', 'Buzones conectados por tipo y estado.', ['tenant', 'provider', 'status']);

        DB::table('mail_connections')->select('tenant_id', 'provider', 'status', DB::raw('count(*) as total'))
            ->groupBy('tenant_id', 'provider', 'status')->get()
            ->each(fn ($row) => $connections->set($row->total, [$this->labels->tenant($row->tenant_id), $row->provider, $row->status]));

        $failures = $registry->registerGauge($this->namespace, 'mail_sync_consecutive_failures', 'Sincronizaciones fallidas seguidas de cada buzón activo (no avanza: posible mensaje que siempre falla).', ['tenant', 'mailbox']);
        $lastSuccess = $registry->registerGauge($this->namespace, 'mail_sync_last_success_timestamp_seconds', 'Última sincronización correcta de cada buzón activo (Unix).', ['tenant', 'mailbox']);

        DB::table('mail_sync_states as s')
            ->join('mail_connections as c', 'c.id', '=', 's.mail_connection_id')
            ->where('c.status', 'active')
            ->select('c.tenant_id', 'c.id', DB::raw('max(s.consecutive_failures) as failures'), DB::raw('max(s.last_success_at) as last_success'))
            ->groupBy('c.tenant_id', 'c.id')->get()
            ->each(function ($row) use ($failures, $lastSuccess) {
                $labels = [$this->labels->tenant($row->tenant_id), (string) $row->id];
                $failures->set($row->failures, $labels);

                if ($row->last_success !== null) {
                    $lastSuccess->set(Carbon::parse($row->last_success)->getTimestamp(), $labels);
                }
            });
    }

    /** Documentos de la base de conocimiento por estado (en cola, indexando, listos, con error). */
    private function documents(CollectorRegistry $registry): void
    {
        $gauge = $registry->registerGauge($this->namespace, 'documents', 'Documentos de la base de conocimiento por estado.', ['tenant', 'status']);

        DB::table('documents')->select('tenant_id', 'status', DB::raw('count(*) as total'))
            ->groupBy('tenant_id', 'status')->get()
            ->each(fn ($row) => $gauge->set($row->total, [$this->labels->tenant($row->tenant_id), $row->status]));
    }

    /** Trabajos esperando en cada cola (config metrics.queues). Una cola que no responde no rompe el resto. */
    private function queues(CollectorRegistry $registry): void
    {
        $gauge = $registry->registerGauge($this->namespace, 'queue_size', 'Trabajos esperando en cada cola.', ['connection', 'queue']);

        foreach (config('metrics.queues', []) as ['connection' => $connection, 'queue' => $queue]) {
            try {
                $gauge->set(Queue::connection($connection)->size($queue), [$connection, $queue]);
            } catch (Throwable $e) {
                Log::warning('No se pudo leer el tamaño de una cola', ['connection' => $connection, 'queue' => $queue, 'detail' => $e->getMessage()]);
            }
        }
    }
}
