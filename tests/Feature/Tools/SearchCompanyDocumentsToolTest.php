<?php

use App\AI\Embeddings\EmbeddingProvider;
use App\AI\Exceptions\LLMUnavailableException;
use App\Documents\DocumentService;
use App\Models\AiRequest;
use App\Models\Email;
use App\Models\Tenant;
use App\Tools\Builtin\SearchCompanyDocumentsTool;
use App\Tools\ToolContext;
use App\Tools\ToolExecutor;
use App\Tools\ToolRegistry;
use App\Tools\ToolResult;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeEmbeddingProvider;

beforeEach(function () {
    Storage::fake('local');
    config(['ai.rag.min_similarity' => 0.1]);
    $this->embeddings = new FakeEmbeddingProvider();
    app()->instance(EmbeddingProvider::class, $this->embeddings);

    $this->tenant = Tenant::factory()->create();
    inTenant($this->tenant, fn () => app(DocumentService::class)->upload(
        "# Plazo para devolver\n\nTienes 30 días naturales desde la entrega para devolver cualquier producto.\n\n# Envíos\n\nEntregamos en 24 o 48 horas.",
        'politica.md',
        'Política de devoluciones',
    ));
});

function searchDocsTool(array $arguments, ?Email $email = null): ToolResult
{
    return app(ToolExecutor::class)->execute('search_company_documents', $arguments, new ToolContext(test()->tenant, $email));
}

it('is available to the ai', function () {
    expect(app(ToolRegistry::class)->get('search_company_documents'))->toBeInstanceOf(SearchCompanyDocumentsTool::class);
});

it('returns the relevant fragments with their source', function () {
    $result = searchDocsTool(['query' => '¿Cuántos días tengo para devolver un producto?', 'limit' => 2]);

    $first = $result->toArray()['results'][0];
    expect($result->ok)->toBeTrue()
        ->and($first['source'])->toBe('Política de devoluciones, sección «Plazo para devolver»')
        ->and($first['content'])->toContain('30 días naturales')
        ->and($first)->toHaveKeys(['document_id', 'similarity'])
        ->and(count($result->toArray()['results']))->toBeLessThanOrEqual(2);
});

it('links the search to the email being processed', function () {
    $email = inTenant($this->tenant, fn () => Email::factory()->create());

    searchDocsTool(['query' => 'devoluciones'], $email);

    expect(inTenant($this->tenant, fn () => AiRequest::where('purpose', 'knowledge_search')->sole()->email_id))->toBe($email->id);
});

it('tells the model not to invent when nothing is relevant', function () {
    config(['ai.rag.min_similarity' => 0.99]);

    $result = searchDocsTool(['query' => '¿Tenéis tienda en Madrid?']);

    expect($result->toArray()['results'])->toBe([])
        ->and($result->toArray()['note'])->toContain('No inventes');
});

it('keeps the number of results within limits', function () {
    $result = searchDocsTool(['query' => 'devolver entrega envíos', 'limit' => 500]);

    expect(count($result->toArray()['results']))->toBeLessThanOrEqual(8);
});

it('reports the search being unavailable without breaking', function () {
    $this->embeddings->failWith = new LLMUnavailableException('No se pudo conectar con Ollama.');

    expect(searchDocsTool(['query' => 'devoluciones'])->toArray())
        ->toBe(['error' => 'La búsqueda en la documentación no está disponible ahora mismo.']);
});

it('requires a query', function () {
    expect(searchDocsTool([])->error)->toContain('query');
});

it('only searches the documentation of the context company', function () {
    $other = Tenant::factory()->create();

    $result = app(ToolExecutor::class)->execute('search_company_documents', ['query' => 'devolver producto 30 días'], new ToolContext($other));

    expect($result->toArray()['results'])->toBe([]);
});
