<?php

namespace App\AI\Embeddings;

use App\AI\CostCalculator;
use App\AI\Data\LLMUsage;
use App\AI\Enums\AiRequestStatus;
use App\AI\Exceptions\LLMException;
use App\Models\AiRequest;
use App\Models\Email;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Única puerta de entrada a los embeddings: calcula los vectores con el
 * proveedor configurado y registra cada llamada en ai_requests (como las
 * del LLM): tokens, latencia, coste (0 en local) o el error. Debe
 * ejecutarse dentro de un tenant.
 */
class EmbeddingService
{
    public const string PURPOSE_INDEXING = 'document_indexing';

    public const string PURPOSE_SEARCH = 'knowledge_search';

    public function __construct(
        private readonly EmbeddingProvider $provider,
        private readonly CostCalculator $costs,
    ) {
    }

    /**
     * @param  list<string>  $texts
     *
     * @throws LLMException ya registrada en ai_requests.
     */
    public function embed(array $texts, EmbeddingInputType $type, string $purpose, ?Email $email = null): EmbeddingResult
    {
        $base = [
            'email_id' => $email?->id,
            'purpose' => $purpose,
            'provider' => $this->provider->name(),
            'requested_model' => $this->provider->model(),
        ];

        try {
            $result = $this->provider->embed($texts, $type);
        } catch (LLMException $e) {
            $record = AiRequest::create($base + ['status' => AiRequestStatus::Failed, 'error' => $e->getMessage()]);
            Log::warning('Embeddings fallidos', ['ai_request_id' => $record->id, 'purpose' => $purpose, 'error' => $e->getMessage(), 'retryable' => $e->retryable]);

            throw $e;
        } catch (Throwable $e) {
            $record = AiRequest::create($base + ['status' => AiRequestStatus::Failed, 'error' => 'Error interno al calcular embeddings.']);
            Log::error('Error inesperado al calcular embeddings', ['ai_request_id' => $record->id, 'purpose' => $purpose, 'detail' => $e->getMessage()]);

            throw $e;
        }

        $record = AiRequest::create($base + [
            'model' => $result->model,
            'input_tokens' => $result->tokens,
            'cost_usd' => $this->costs->forUsage($result->model, new LLMUsage($result->tokens)),
            'latency_ms' => $result->latencyMs,
            'status' => AiRequestStatus::Success,
            'response' => ['texts' => count($texts), 'requests' => $result->requests, 'dimensions' => $this->provider->dimensions()],
        ]);

        Log::info('Embeddings calculados', [
            'ai_request_id' => $record->id,
            'purpose' => $purpose,
            'texts' => count($texts),
            'tokens' => $result->tokens,
            'latency_ms' => $result->latencyMs,
        ]);

        return $result;
    }
}
