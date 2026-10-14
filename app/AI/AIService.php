<?php

namespace App\AI;

use App\AI\Contracts\LLMProvider;
use App\AI\Data\AIResult;
use App\AI\Data\LLMRequest;
use App\AI\Data\LLMResponse;
use App\AI\Data\LLMToolCall;
use App\AI\Enums\AiRequestStatus;
use App\AI\Exceptions\LLMException;
use App\AI\Exceptions\LLMRefusalException;
use App\AI\Prompts\Prompt;
use App\AI\Prompts\PromptRegistry;
use App\Models\AiRequest;
use App\Models\Email;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Única puerta de entrada a la IA para el resto de la aplicación: ejecuta
 * un prompt versionado con el proveedor configurado y deja SIEMPRE
 * constancia en ai_requests (éxito, error o rechazo, con los tokens y el
 * coste facturados). Debe ejecutarse dentro de un tenant.
 */
class AIService
{
    public function __construct(
        private readonly LLMProvider $provider,
        private readonly PromptRegistry $prompts,
        private readonly CostCalculator $costs,
    ) {
    }

    /** La IA está configurada (hay API key). Sin ella no se llama a nada. */
    public static function enabled(): bool
    {
        return match (config('ai.provider')) {
            'anthropic' => (string) config('ai.anthropic.api_key') !== '',
            default => false,
        };
    }

    /**
     * @throws LLMException el error del proveedor, ya registrado en ai_requests.
     */
    public function run(Prompt $prompt, string $userContent, int $maxTokens, ?string $effort = null, ?Email $email = null): AIResult
    {
        return $this->converse($prompt, [['role' => 'user', 'content' => $userContent]], $maxTokens, $effort, email: $email);
    }

    /**
     * Un paso de una conversación (p. ej. del agente): mismos registros que
     * run(), con herramientas y enlazado a la ejecución del agente. La
     * conversación solo se AÑADE (ver LLMRequest).
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array{name: string, description: string, input_schema: array<string, mixed>}>  $tools
     *
     * @throws LLMException ya registrado en ai_requests.
     */
    public function converse(
        Prompt $prompt,
        array $messages,
        int $maxTokens,
        ?string $effort = null,
        array $tools = [],
        ?Email $email = null,
        ?int $agentRunId = null,
    ): AIResult {
        $version = $this->prompts->resolve($prompt);
        $request = new LLMRequest($prompt->system(), $messages, $maxTokens, $prompt->outputSchema(), $effort, tools: $tools);
        $base = [
            'email_id' => $email?->id,
            'agent_run_id' => $agentRunId,
            'ai_prompt_version_id' => $version->id,
            'purpose' => $prompt->key(),
            'provider' => $this->provider->name(),
            'requested_model' => $request->model ?? self::configuredModel(),
        ];

        try {
            $response = $this->provider->generate($request);
        } catch (LLMException $e) {
            $record = AiRequest::create($base + $this->usageAttributes($e->response) + [
                'status' => $e instanceof LLMRefusalException ? AiRequestStatus::Refused : AiRequestStatus::Failed,
                'error' => $e->getMessage(),
            ]);
            $this->log('warning', 'Petición a la IA fallida', $record, ['retryable' => $e->retryable]);

            throw $e;
        } catch (Throwable $e) {
            // Fallo inesperado (bug): se registra sin detalles internos y se relanza.
            $record = AiRequest::create($base + [
                'status' => AiRequestStatus::Failed,
                'error' => 'Error interno al llamar a la IA.',
            ]);
            $this->log('error', 'Error inesperado al llamar a la IA', $record, ['detail' => $e->getMessage()]);

            throw $e;
        }

        $record = AiRequest::create($base + $this->usageAttributes($response) + [
            'status' => AiRequestStatus::Success,
            // Si la respuesta trae intención/confianza (clasificación), se guardan para métricas.
            'intent' => is_string($response->data['intent'] ?? null) ? $response->data['intent'] : null,
            'confidence' => is_numeric($response->data['confidence'] ?? null) ? max(0, min(1, (float) $response->data['confidence'])) : null,
            // Si pide herramientas, se registra qué pidió (los resultados van a ai_tool_calls).
            'response' => $response->wantsTools()
                ? ['tool_calls' => array_map(fn (LLMToolCall $call) => ['name' => $call->name, 'input' => $call->input], $response->toolCalls)]
                : $response->data,
        ]);
        $this->log('info', 'Petición a la IA', $record);

        return new AIResult($record, $response);
    }

    /** @return array<string, mixed> */
    private function usageAttributes(?LLMResponse $response): array
    {
        if ($response === null) {
            return [];
        }

        return [
            'model' => $response->model,
            'used_fallback' => $response->usedFallback,
            'input_tokens' => $response->usage->inputTokens,
            'output_tokens' => $response->usage->outputTokens,
            'cache_write_tokens' => $response->usage->cacheWriteTokens,
            'cache_read_tokens' => $response->usage->cacheReadTokens,
            'cost_usd' => $this->costs->forResponse($response),
            'latency_ms' => $response->latencyMs,
            'stop_reason' => $response->stopReason,
            'provider_request_id' => $response->requestId,
        ];
    }

    private static function configuredModel(): string
    {
        return (string) config('ai.' . config('ai.provider') . '.model', 'desconocido');
    }

    /** @param  array<string, mixed>  $extra */
    private function log(string $level, string $message, AiRequest $record, array $extra = []): void
    {
        Log::log($level, $message, [
            'ai_request_id' => $record->id,
            'purpose' => $record->purpose,
            'email_id' => $record->email_id,
            'model' => $record->model ?? $record->requested_model,
            'status' => $record->status->value,
            'tokens' => $record->input_tokens + $record->output_tokens,
            'cost_usd' => $record->cost_usd,
            'latency_ms' => $record->latency_ms,
            'error' => $record->error,
        ] + $extra);
    }
}
