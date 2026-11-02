<?php

namespace App\Observability;

use App\Billing\PlanCatalog;
use App\Billing\PlanLimit;
use App\Billing\UsageMeter;
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

        $ok = $registry->registerGauge($this->namespace, 'state_metrics_ok', 'Si se pudo calcular cada sección del estado actual (1) o falló (0).', ['section']);

        // Cada sección por separado: si la BD no responde, /metrics sigue
        // publicando los contadores y el resto (y state_metrics_ok lo delata).
        $sections = ['emails', 'mailboxes', 'documents', 'queues', 'plans'];

        // Copias de seguridad (Fase 16): solo donde existen (producción).
        if (filled(config('metrics.backup_status_file'))) {
            $sections[] = 'backups';
        }

        foreach ($sections as $section) {
            try {
                $this->{$section}($registry);
                $ok->set(1, [$section]);
            } catch (Throwable $e) {
                $ok->set(0, [$section]);
                Log::warning('No se pudo calcular una métrica de estado', ['section' => $section, 'detail' => $e->getMessage()]);
            }
        }

        return (new RenderTextFormat())->render($registry->getMetricFamilySamples());
    }

    /**
     * Hora de la última copia de seguridad correcta (y restaurada en la
     * prueba), que escribe el contenedor "backup". Sin fichero = 0: nunca ha
     * salido bien una copia, y la alerta CopiaDeSeguridadAtrasada lo avisa.
     */
    private function backups(CollectorRegistry $registry): void
    {
        $gauge = $registry->registerGauge($this->namespace, 'backup_last_success_timestamp_seconds', 'Última copia de seguridad correcta y verificada (Unix; 0 = ninguna).');

        $file = (string) config('metrics.backup_status_file');
        $content = is_readable($file) ? trim((string) file_get_contents($file)) : '';

        $gauge->set(ctype_digit($content) ? (int) $content : 0);
    }

    /**
     * Plan, suscripción y uso del mes de cada empresa activa (Fase 17). Con
     * consultas agregadas (una por recurso), no una por empresa. Solo para el
     * operador: aquí sí entra el gasto de IA (como fracción del límite).
     */
    private function plans(CollectorRegistry $registry): void
    {
        $plan = $registry->registerGauge($this->namespace, 'tenant_plan', 'Plan y estado de la suscripción de cada empresa (siempre 1).', ['tenant', 'plan', 'status']);
        $trial = $registry->registerGauge($this->namespace, 'tenant_trial_ends_timestamp_seconds', 'Fin de la prueba de las empresas en prueba (Unix).', ['tenant']);
        $ratio = $registry->registerGauge($this->namespace, 'tenant_plan_usage_ratio', 'Parte usada de cada límite del plan (1 = alcanzado).', ['tenant', 'limit']);

        $tenants = DB::table('tenants')->where('is_active', true)->get(['id', 'plan', 'subscription_status', 'trial_ends_at']);

        if ($tenants->isEmpty()) {
            return;
        }

        $month = UsageMeter::period();
        $used = [
            PlanLimit::AiEmailsPerMonth->value => DB::table('tenant_usage')->where('period', $month->toDateString())->pluck('ai_emails', 'tenant_id'),
            PlanLimit::AiBudgetUsdPerMonth->value => DB::table('ai_requests')->where('created_at', '>=', $month)->groupBy('tenant_id')->select('tenant_id', DB::raw('sum(cost_usd) as total'))->pluck('total', 'tenant_id'),
            PlanLimit::Mailboxes->value => DB::table('mail_connections')->where('status', '!=', 'disabled')->groupBy('tenant_id')->select('tenant_id', DB::raw('count(*) as total'))->pluck('total', 'tenant_id'),
            PlanLimit::Users->value => DB::table('users')->groupBy('tenant_id')->select('tenant_id', DB::raw('count(*) as total'))->pluck('total', 'tenant_id'),
            PlanLimit::Documents->value => DB::table('documents')->groupBy('tenant_id')->select('tenant_id', DB::raw('count(*) as total'))->pluck('total', 'tenant_id'),
        ];

        foreach ($tenants as $tenant) {
            $label = $this->labels->tenant($tenant->id);
            $current = PlanCatalog::find($tenant->plan) ?? PlanCatalog::get('trial');
            $plan->set(1, [$label, $current->key, $tenant->subscription_status]);

            if ($tenant->subscription_status === 'trialing' && $tenant->trial_ends_at !== null) {
                $trial->set(Carbon::parse($tenant->trial_ends_at)->getTimestamp(), [$label]);
            }

            foreach (PlanLimit::cases() as $limit) {
                $max = $current->limit($limit);

                if ($max !== null) {
                    $ratio->set($max > 0 ? round((float) ($used[$limit->value][$tenant->id] ?? 0) / $max, 4) : 1, [$label, $limit->value]);
                }
            }
        }
    }

    /** Emails por estado ahora mismo (p. ej. cuántos esperan a una persona). */
    private function emails(CollectorRegistry $registry): void
    {
        $gauge = $registry->registerGauge($this->namespace, 'emails_current', 'Emails por estado en este momento.', ['tenant', 'status']);

        DB::table('emails')->select('tenant_id', 'status', DB::raw('count(*) as total'))
            ->groupBy('tenant_id', 'status')->get()
            ->each(fn ($row) => $gauge->set($row->total, [$this->labels->tenant($row->tenant_id), $row->status]));

        // Revisión humana: cuánto lleva esperando el caso más antiguo de cada empresa.
        $oldest = $registry->registerGauge($this->namespace, 'email_review_oldest_waiting_seconds', 'Segundos que lleva esperando a una persona el caso más antiguo (revisión humana o error).', ['tenant']);
        $waitingSince = DB::table('email_status_changes')->selectRaw('max(created_at)')->whereColumn('email_id', 'emails.id');

        DB::table('emails')
            ->whereIn('status', ['human_review', 'failed'])
            ->select('tenant_id')
            ->selectSub($waitingSince, 'waiting_since')
            ->get()
            ->groupBy('tenant_id')
            ->each(function ($rows, $tenantId) use ($oldest) {
                $since = $rows->pluck('waiting_since')->filter()->min();

                if ($since !== null) {
                    $oldest->set(max(0, now()->getTimestamp() - Carbon::parse($since)->getTimestamp()), [$this->labels->tenant((int) $tenantId)]);
                }
            });
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
