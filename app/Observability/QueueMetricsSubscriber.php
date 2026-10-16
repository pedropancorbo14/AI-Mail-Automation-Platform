<?php

namespace App\Observability;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Str;

/**
 * Trabajos de cola: cuántos terminan bien, cuántos se devuelven a la cola
 * para reintentar y cuántos fallan definitivamente, y cuánto tardan.
 *
 * Se cuenta con JobAttempted, que Laravel lanza al final de CADA intento
 * (en el worker y en la cola sync) cuando el estado del trabajo ya está
 * decidido. No sirve JobProcessed: solo llega si el trabajo terminó sin
 * excepción (los reintentos por excepción no se verían) y también llega
 * tras un $this->fail() dentro del trabajo (se contaría dos veces).
 */
class QueueMetricsSubscriber
{
    /** @var array<string, int> Inicio (hrtime) de cada trabajo en marcha en este proceso. */
    private array $started = [];

    public function __construct(private readonly Metrics $metrics)
    {
    }

    public function processing(JobProcessing $event): void
    {
        $this->started[$this->key($event->job)] = hrtime(true);
    }

    public function attempted(JobAttempted $event): void
    {
        $job = $event->job;
        $result = match (true) {
            $job->hasFailed() => 'failed',
            $job->isReleased() => 'released',
            default => 'success',
        };

        $labels = ['queue' => $job->getQueue() ?? 'none', 'job_class' => Str::afterLast($job->resolveName(), '\\')];
        $this->metrics->increment('jobs_processed_total', $labels + ['result' => $result]);

        $startedAt = $this->started[$key = $this->key($job)] ?? null;
        unset($this->started[$key]);

        if ($startedAt !== null && $result !== 'failed') {
            $this->metrics->observe('job_duration_seconds', (hrtime(true) - $startedAt) / 1e9, $labels);
        }
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(JobProcessing::class, [$this, 'processing']);
        $events->listen(JobAttempted::class, [$this, 'attempted']);
    }

    private function key(Job $job): string
    {
        return $job->getConnectionName() . ':' . ($job->getJobId() ?? spl_object_id($job));
    }
}
