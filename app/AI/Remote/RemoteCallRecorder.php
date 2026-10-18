<?php

namespace App\AI\Remote;

use App\AI\CostCalculator;
use App\AI\Data\LLMUsage;
use App\AI\Enums\AiRequestStatus;
use App\Models\AiRequest;
use App\Models\Email;

/**
 * Guarda en ai_requests las llamadas a modelos que hizo ai-service (formato
 * `llm_call` del contrato): una fila por llamada, también las fallidas (se
 * facturan). El coste lo calcula Laravel con sus precios (config/ai.php): así
 * Grafana, la auditoría y AiSpendGuard ven todo el gasto. Dentro del tenant.
 */
class RemoteCallRecorder
{
    public function __construct(private readonly CostCalculator $costs)
    {
    }

    /**
     * @param  list<array<string, mixed>>  $calls
     * @return list<AiRequest>
     */
    public function record(?Email $email, array $calls): array
    {
        return array_map(function (array $call) use ($email) {
            $usage = new LLMUsage($call['input_tokens'], $call['output_tokens'], $call['cache_write_tokens'], $call['cache_read_tokens']);

            return AiRequest::create([
                'email_id' => $email?->id,
                'purpose' => $call['purpose'],
                'provider' => $call['provider'],
                'requested_model' => $call['requested_model'],
                'model' => $call['model'],
                'used_fallback' => $call['model'] !== $call['requested_model'],
                'input_tokens' => $usage->inputTokens,
                'output_tokens' => $usage->outputTokens,
                'cache_write_tokens' => $usage->cacheWriteTokens,
                'cache_read_tokens' => $usage->cacheReadTokens,
                'cost_usd' => $this->costs->forUsage($call['model'], $usage),
                'latency_ms' => $call['latency_ms'],
                'status' => AiRequestStatus::from($call['status']),
                'stop_reason' => $call['stop_reason'] ?? null,
                'error' => $call['error'] ?? null,
                'provider_request_id' => $call['provider_request_id'] ?? null,
                'response' => ['source' => 'ai-service', 'prompt' => $call['prompt'] ?? null],
            ]);
        }, $calls);
    }
}
