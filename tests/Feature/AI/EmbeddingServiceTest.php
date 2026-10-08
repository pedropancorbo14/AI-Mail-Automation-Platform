<?php

use App\AI\Embeddings\EmbeddingInputType;
use App\AI\Embeddings\EmbeddingProvider;
use App\AI\Embeddings\EmbeddingService;
use App\AI\Enums\AiRequestStatus;
use App\AI\Exceptions\LLMUnavailableException;
use App\Models\AiRequest;
use App\Models\Email;
use App\Models\Tenant;
use Tests\Fakes\FakeEmbeddingProvider;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->embeddings = new FakeEmbeddingProvider();
    app()->instance(EmbeddingProvider::class, $this->embeddings);
});

function embedInTenant(array $texts, string $purpose = EmbeddingService::PURPOSE_INDEXING, ?Email $email = null)
{
    return inTenant(test()->tenant, fn () => app(EmbeddingService::class)->embed($texts, EmbeddingInputType::Document, $purpose, $email));
}

it('returns the vectors and records the call with tokens, latency and zero cost', function () {
    $result = embedInTenant(['Política de devoluciones', 'Plazos de envío']);
    $record = inTenant($this->tenant, fn () => AiRequest::sole());

    expect($result->vectors)->toHaveCount(2)
        ->and($record->only('purpose', 'provider', 'requested_model', 'model', 'cost_usd', 'latency_ms'))->toBe([
            'purpose' => 'document_indexing',
            'provider' => 'fake',
            'requested_model' => 'bge-m3',
            'model' => 'bge-m3',
            'cost_usd' => '0.000000',
            'latency_ms' => 120,
        ])
        ->and($record->input_tokens)->toBe($result->tokens)
        ->and($record->status)->toBe(AiRequestStatus::Success)
        ->and($record->response)->toBe(['texts' => 2, 'requests' => 1, 'dimensions' => 1024])
        ->and($record->tenant_id)->toBe($this->tenant->id);
});

it('links searches made for an email', function () {
    $email = inTenant($this->tenant, fn () => Email::factory()->create());

    embedInTenant(['¿cómo devuelvo un producto?'], EmbeddingService::PURPOSE_SEARCH, $email);

    expect(inTenant($this->tenant, fn () => AiRequest::sole()->only('purpose', 'email_id')))
        ->toBe(['purpose' => 'knowledge_search', 'email_id' => $email->id]);
});

it('records failures and lets the error through', function () {
    $this->embeddings->failWith = new LLMUnavailableException('No se pudo conectar con Ollama.');

    expect(fn () => embedInTenant(['x']))->toThrow(LLMUnavailableException::class);

    $record = inTenant($this->tenant, fn () => AiRequest::sole());
    expect($record->status)->toBe(AiRequestStatus::Failed)
        ->and($record->error)->toBe('No se pudo conectar con Ollama.');
});

it('hides unexpected internal errors in the record', function () {
    $this->embeddings->failWith = new RuntimeException('detalle interno');

    expect(fn () => embedInTenant(['x']))->toThrow(RuntimeException::class);

    expect(inTenant($this->tenant, fn () => AiRequest::sole()->error))->toBe('Error interno al calcular embeddings.');
});
