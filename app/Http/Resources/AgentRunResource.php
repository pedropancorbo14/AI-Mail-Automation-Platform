<?php

namespace App\Http\Resources;

use App\Models\AiRequest;
use App\Models\AiToolCall;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una ejecución del agente con sus pasos y las herramientas que usó en cada
 * uno: la traza completa de lo que hizo la IA con un email. Sin tokens ni
 * costes: los ven los clientes; esos datos son del operador (Grafana).
 *
 * @mixin \App\Models\AiAgentRun
 */
class AgentRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'stop_reason' => $this->stop_reason?->value,
            'stop_reason_label' => $this->stop_reason?->label(),
            'error' => $this->error,
            'iterations' => $this->iterations,
            'tool_calls_count' => $this->tool_calls_count,
            'duration_ms' => $this->duration_ms,
            // Especialistas de ai-service que intervinieron (el primero, el principal).
            'specialists' => $this->final_output['specialists'] ?? [],
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'steps' => $this->whenLoaded('aiRequests', fn () => $this->aiRequests->map(fn (AiRequest $step) => [
                'id' => $step->id,
                'status' => $step->status->value,
                'model' => $step->model,
                'latency_ms' => $step->latency_ms,
                'error' => $step->error,
                'tool_calls' => $step->toolCalls->map(fn (AiToolCall $call) => [
                    'id' => $call->id,
                    'tool' => $call->tool,
                    'arguments' => $call->arguments,
                    'result' => $call->result,
                    'status' => $call->status->value,
                    // plan, model o approval (ai-service); null = el motor de Laravel.
                    'origin' => $call->origin,
                    'error' => $call->error,
                    'duration_ms' => $call->duration_ms,
                ])->all(),
            ])->all()),
        ];
    }
}
