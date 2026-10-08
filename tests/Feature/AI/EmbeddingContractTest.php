<?php

use App\AI\Embeddings\EmbeddingInputType;
use App\AI\Exceptions\LLMRateLimitException;
use Tests\Fakes\FakeEmbeddingProvider;

it('configures local ollama embeddings with 1024 dimensions and zero cost', function () {
    expect(config('ai.embeddings.provider'))->toBe('ollama')
        ->and(config('ai.embeddings.dimensions'))->toBe(1024)
        ->and(config('ai.embeddings.ollama.model'))->toBe('bge-m3')
        ->and(config('ai.embeddings.ollama.base_url'))->toBe('http://ollama:11434')
        ->and(config('ai.pricing.bge-m3.input'))->toBe(0.0);
});

it('runs ollama only inside the internal docker network', function () {
    $compose = file_get_contents(base_path('docker-compose.yml'));
    $ollama = substr($compose, strpos($compose, "\n  ollama:"), 600);

    expect($ollama)->toContain('ollama/ollama:')
        ->not->toContain('ports:');
});

it('fakes normalized embeddings where shared words mean similar texts', function () {
    $fake = new FakeEmbeddingProvider();
    [$devolucion, $devolucion2, $envio] = $fake->embed([
        'Política de devoluciones: 30 días para devolver el producto',
        '¿Cómo devolver un producto? Tienes 30 días',
        'Los envíos tardan entre 24 y 48 horas laborables',
    ], EmbeddingInputType::Document)->vectors;

    $cosine = fn (array $a, array $b) => array_sum(array_map(fn ($x, $y) => $x * $y, $a, $b));

    expect(count($devolucion))->toBe(1024)
        ->and(round($cosine($devolucion, $devolucion), 6))->toBe(1.0)
        ->and($cosine($devolucion, $devolucion2))->toBeGreaterThan($cosine($devolucion, $envio));
});

it('records the calls and can simulate errors', function () {
    $fake = new FakeEmbeddingProvider();
    $fake->embed(['hola'], EmbeddingInputType::Query);
    $fake->failWith = new LLMRateLimitException('Límite');

    expect(fn () => $fake->embed(['x'], EmbeddingInputType::Query))->toThrow(LLMRateLimitException::class)
        ->and($fake->calls[0]['type'])->toBe(EmbeddingInputType::Query);
});
