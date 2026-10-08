<?php

use App\AI\Embeddings\EmbeddingInputType;
use App\AI\Embeddings\EmbeddingProvider;
use App\AI\Embeddings\OllamaEmbeddingProvider;
use App\AI\Exceptions\LLMConfigurationException;
use App\AI\Exceptions\LLMInvalidResponseException;
use App\AI\Exceptions\LLMUnavailableException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

function ollama(int $dimensions = 4, int $batchSize = 16): OllamaEmbeddingProvider
{
    return new OllamaEmbeddingProvider('http://ollama:11434', 'bge-m3', $dimensions, 30, $batchSize);
}

/** Respuesta de /api/embed con un vector [n, 0, 0, 0] por texto. */
function fakeOllamaEmbed(): void
{
    Http::fake(['ollama:11434/api/embed' => function (Request $request) {
        return Http::response([
            'model' => 'bge-m3',
            'embeddings' => array_map(fn ($i) => [3.0 + $i, 4.0, 0.0, 0.0], array_keys($request['input'])),
            'prompt_eval_count' => 10 * count($request['input']),
        ]);
    }]);
}

it('asks ollama for one normalized vector per text', function () {
    fakeOllamaEmbed();

    $result = ollama()->embed(['Política de devoluciones', 'Plazos de envío'], EmbeddingInputType::Document);

    Http::assertSent(fn (Request $request) => $request->url() === 'http://ollama:11434/api/embed'
        && $request['model'] === 'bge-m3'
        && $request['input'] === ['Política de devoluciones', 'Plazos de envío']
        && $request['truncate'] === true);

    expect($result->vectors)->toHaveCount(2)
        ->and($result->vectors[0])->toBe([0.6, 0.8, 0.0, 0.0])
        ->and(round(array_sum(array_map(fn ($v) => $v * $v, $result->vectors[1])), 10))->toBe(1.0)
        ->and($result->tokens)->toBe(20)
        ->and($result->model)->toBe('bge-m3')
        ->and($result->requests)->toBe(1);
});

it('splits big inputs into batches keeping the order', function () {
    fakeOllamaEmbed();

    $result = ollama(batchSize: 2)->embed(['a', 'b', 'c', 'd', 'e'], EmbeddingInputType::Document);

    Http::assertSentCount(3);
    expect($result->vectors)->toHaveCount(5)
        ->and($result->requests)->toBe(3)
        ->and($result->tokens)->toBe(50);
});

it('explains how to download a missing model', function () {
    Http::fake(['*' => Http::response(['error' => 'model "bge-m3" not found, try pulling it first'], 404)]);

    ollama()->embed(['x'], EmbeddingInputType::Query);
})->throws(LLMConfigurationException::class, 'docker compose exec ollama ollama pull bge-m3');

it('reports ollama being down as retryable', function () {
    Http::fake(['*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused')]);

    try {
        ollama()->embed(['x'], EmbeddingInputType::Query);
        $this->fail('Debería lanzar LLMUnavailableException');
    } catch (LLMUnavailableException $e) {
        expect($e->retryable)->toBeTrue()
            ->and($e->getMessage())->toContain('servicio ollama');
    }
});

it('reports server errors as retryable', function () {
    Http::fake(['*' => Http::response(['error' => 'out of memory'], 500)]);

    ollama()->embed(['x'], EmbeddingInputType::Query);
})->throws(LLMUnavailableException::class, 'out of memory');

it('rejects vectors with the wrong dimensions', function () {
    Http::fake(['*' => Http::response(['embeddings' => [[1.0, 0.0]], 'prompt_eval_count' => 1])]);

    ollama(dimensions: 4)->embed(['x'], EmbeddingInputType::Query);
})->throws(LLMInvalidResponseException::class, 'base de datos espera 4');

it('rejects a missing vector', function () {
    Http::fake(['*' => Http::response(['embeddings' => [[1.0, 0.0, 0.0, 0.0]]])]);

    ollama()->embed(['a', 'b'], EmbeddingInputType::Query);
})->throws(LLMInvalidResponseException::class);

it('is the configured embedding provider', function () {
    $provider = app(EmbeddingProvider::class);

    expect($provider)->toBeInstanceOf(OllamaEmbeddingProvider::class)
        ->and($provider->model())->toBe('bge-m3')
        ->and($provider->dimensions())->toBe(1024);
});
