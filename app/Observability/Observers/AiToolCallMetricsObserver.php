<?php

namespace App\Observability\Observers;

use App\Models\AiToolCall;
use App\Observability\MetricLabels;
use App\Observability\Metrics;

/** Herramientas que ha pedido la IA (ejecutadas, con error o rechazadas) y su duración. */
class AiToolCallMetricsObserver
{
    public bool $afterCommit = true;

    public function __construct(private readonly Metrics $metrics, private readonly MetricLabels $labels)
    {
    }

    public function created(AiToolCall $call): void
    {
        $this->metrics->increment('ai_tool_calls_total', [
            'tenant' => $this->labels->tenant($call->tenant_id),
            'tool' => $call->tool,
            'status' => $call->status->value,
        ]);

        if ($call->duration_ms !== null) {
            $this->metrics->observe('ai_tool_call_duration_seconds', $call->duration_ms / 1000, ['tool' => $call->tool]);
        }
    }
}
