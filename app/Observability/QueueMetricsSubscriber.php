<?php

namespace App\Observability;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Str;

/**
 * Trabajos de cola: cuántos terminan bien, cuántos se devuelven a la cola
 * para reintentar y cuántos fallan definitivamente, y cuánto tardan.
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

    public function processed(JobProcessed $event): void
    {
        // Un trabajo que lanzó una excepción y se reintentará también llega aquí, "released".
        $this->finish($event->job, $event->job->isReleased() ? 'released' : 'success');
    }

    public function failed(JobFailed $event): void
    {
        $this->finish($event->job, 'failed');
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(JobProcessing::class, [$this, 'processing']);
        $events->listen(JobProcessed::class, [$this, 'processed']);
        $events->listen(JobFailed::class, [$this, 'failed']);
    }

    private function finish(Job $job, string $result): void
    {
        $labels = ['queue' => $job->getQueue() ?? 'none', 'job_class' => Str::afterLast($job->resolveName(), '\\')];
        $this->metrics->increment('jobs_processed_total', $labels + ['result' => $result]);

        $startedAt = $this->started[$key = $this->key($job)] ?? null;
        unset($this->started[$key]);

        if ($startedAt !== null && $result !== 'failed') {
            $this->metrics->observe('job_duration_seconds', (hrtime(true) - $startedAt) / 1e9, $labels);
        }
    }

    private function key(Job $job): string
    {
        return $job->getConnectionName() . ':' . ($job->getJobId() ?? spl_object_id($job));
    }
}
