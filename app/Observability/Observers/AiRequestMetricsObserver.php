<?php

namespace App\Observability\Observers;

use App\AI\Enums\AiRequestStatus;
use App\Models\AiRequest;
use App\Observability\MetricLabels;
use App\Observability\Metrics;

/**
 * Cada petición a la IA (LLM o embeddings) tal como queda en ai_requests:
 * estado, tokens, coste y latencia; en las clasificaciones, también la
 * intención y la confianza.
 */
class AiRequestMetricsObserver
{
    private const array TOKEN_COLUMNS = [
        'input' => 'input_tokens',
        'output' => 'output_tokens',
        'cache_write' => 'cache_write_tokens',
        'cache_read' => 'cache_read_tokens',
    ];

    public bool $afterCommit = true;

    public function __construct(private readonly Metrics $metrics, private readonly MetricLabels $labels)
    {
    }

    public function created(AiRequest $request): void
    {
        $tenant = $this->labels->tenant($request->tenant_id);
        // Modelo que respondió; si falló antes de responder, el que se pidió.
        $model = $request->model ?? $request->requested_model ?? 'none';
        $purpose = $request->purpose;

        $this->metrics->increment('ai_requests_total', [
            'tenant' => $tenant, 'provider' => $request->provider, 'model' => $model,
            'purpose' => $purpose, 'status' => $request->status->value,
        ]);

        foreach (self::TOKEN_COLUMNS as $type => $column) {
            $this->metrics->increment('ai_tokens_total', ['tenant' => $tenant, 'model' => $model, 'purpose' => $purpose, 'type' => $type], (float) $request->{$column});
        }

        if ($request->cost_usd !== null) {
            $this->metrics->increment('ai_cost_usd_total', ['tenant' => $tenant, 'model' => $model, 'purpose' => $purpose], (float) $request->cost_usd);
        }

        if ($request->latency_ms !== null) {
            $this->metrics->observe('ai_request_duration_seconds', $request->latency_ms / 1000, ['provider' => $request->provider, 'model' => $model, 'purpose' => $purpose]);
        }

        if (str_ends_with($purpose, 'classification') && $request->status === AiRequestStatus::Success && $request->intent !== null) {
            $this->metrics->increment('ai_classifications_total', [
                'tenant' => $tenant,
                'intent' => $request->intent,
                'needs_human' => (bool) ($request->response['needs_human'] ?? false),
            ]);

            if ($request->confidence !== null) {
                $this->metrics->observe('ai_classification_confidence', (float) $request->confidence, ['intent' => $request->intent]);
            }
        }
    }
}
