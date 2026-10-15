<?php

namespace App\Observability\Observers;

use App\AI\Agent\Enums\AgentRunStatus;
use App\Models\AiAgentRun;
use App\Observability\MetricLabels;
use App\Observability\Metrics;

/** Ejecuciones del agente al terminar: cómo acabaron (motivo de fin) y cuánto tardaron. */
class AiAgentRunMetricsObserver
{
    public bool $afterCommit = true;

    public function __construct(private readonly Metrics $metrics, private readonly MetricLabels $labels)
    {
    }

    public function updated(AiAgentRun $run): void
    {
        if (! $run->wasChanged('status') || $run->status === AgentRunStatus::Running) {
            return;
        }

        $stopReason = $run->stop_reason?->value ?? 'none';

        $this->metrics->increment('ai_agent_runs_total', [
            'tenant' => $this->labels->tenant($run->tenant_id),
            'status' => $run->status->value,
            'stop_reason' => $stopReason,
        ]);

        if ($run->duration_ms !== null) {
            $this->metrics->observe('ai_agent_run_duration_seconds', $run->duration_ms / 1000, ['stop_reason' => $stopReason]);
        }
    }
}
