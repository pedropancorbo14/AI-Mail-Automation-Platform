<?php

namespace App\AI\Embeddings;

use App\AI\Exceptions\LLMConfigurationException;
use App\AI\Exceptions\LLMInvalidResponseException;
use App\AI\Exceptions\LLMUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Embeddings calculados en local con Ollama (POST /api/embed). Los textos
 * no salen del servidor. Los vectores se normalizan a longitud 1 para que
 * la distancia coseno de pgvector sea fiable.
 */
class OllamaEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $dimensions,
        private readonly int $timeout = 120,
        private readonly int $batchSize = 16,
    ) {
    }

    public function name(): string
    {
        return 'ollama';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    public function embed(array $texts, EmbeddingInputType $type): EmbeddingResult
    {
        $startedAt = hrtime(true);
        $vectors = [];
        $tokens = 0;
        $requests = 0;

        // bge-m3 no distingue pregunta/documento; $type queda para otros modelos.
        foreach (array_chunk(array_values($texts), max(1, $this->batchSize)) as $batch) {
            $body = $this->request($batch);
            $embeddings = $body['embeddings'] ?? null;

            if (! is_array($embeddings) || count($embeddings) !== count($batch)) {
                throw new LLMInvalidResponseException('Ollama no devolvió un vector por cada texto.');
            }

            foreach ($embeddings as $vector) {
                $vectors[] = $this->normalize($vector);
            }

            $tokens += (int) ($body['prompt_eval_count'] ?? 0);
            $requests++;
        }

        return new EmbeddingResult($vectors, $this->model, $tokens, (int) ((hrtime(true) - $startedAt) / 1_000_000), $requests);
    }

    /**
     * @param  list<string>  $batch
     * @return array<string, mixed>
     */
    private function request(array $batch): array
    {
        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeout)
                ->acceptJson()
                ->post('/api/embed', [
                    'model' => $this->model,
                    'input' => $batch,
                    // Textos más largos que el contexto del modelo se recortan en vez de fallar.
                    'truncate' => true,
                ]);
        } catch (ConnectionException $e) {
            throw new LLMUnavailableException("No se pudo conectar con Ollama ({$this->baseUrl}): ¿está levantado el servicio ollama?", previous: $e);
        }

        return match (true) {
            $response->successful() => $response->json(),
            $response->status() === 404 => throw new LLMConfigurationException(
                "El modelo {$this->model} no está descargado en Ollama: docker compose exec ollama ollama pull {$this->model}",
            ),
            $response->serverError() => throw new LLMUnavailableException('Ollama falló (' . $response->status() . '): ' . self::error($response)),
            default => throw new LLMConfigurationException('Ollama rechazó la petición (' . $response->status() . '): ' . self::error($response)),
        };
    }

    /**
     * @param  mixed  $vector
     * @return list<float>
     */
    private function normalize(mixed $vector): array
    {
        if (! is_array($vector) || count($vector) !== $this->dimensions) {
            throw new LLMInvalidResponseException(
                "El modelo {$this->model} devuelve vectores de " . (is_array($vector) ? count($vector) : 0)
                . " dimensiones y la base de datos espera {$this->dimensions}.",
            );
        }

        $norm = sqrt(array_sum(array_map(fn ($v) => (float) $v * (float) $v, $vector)));

        if ($norm == 0.0) {
            throw new LLMInvalidResponseException('Ollama devolvió un vector vacío (todo ceros).');
        }

        return array_map(fn ($v) => (float) $v / $norm, $vector);
    }

    private static function error(Response $response): string
    {
        return (string) ($response->json('error') ?? 'sin detalle');
    }
}
