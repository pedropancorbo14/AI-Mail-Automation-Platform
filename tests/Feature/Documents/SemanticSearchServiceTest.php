<?php

use App\AI\Embeddings\EmbeddingProvider;
use App\Documents\DocumentService;
use App\Documents\Search\SearchResult;
use App\Documents\Search\SemanticSearchService;
use App\Models\AiRequest;
use App\Models\Document;
use App\Models\Email;
use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeEmbeddingProvider;
use Tests\Support\Pdf;

beforeEach(function () {
    Storage::fake('local');
    $this->tenant = Tenant::factory()->create();
    $this->embeddings = new FakeEmbeddingProvider();
    app()->instance(EmbeddingProvider::class, $this->embeddings);

    $this->policy = indexDocumentIn($this->tenant, "# Devoluciones\n\nPuedes devolver cualquier producto en un plazo de 30 días desde la entrega.\n\n# Envíos\n\nLos pedidos se entregan en 24 o 48 horas laborables.", 'politica.md', 'Política de la tienda');
    $this->warranty = indexDocumentIn($this->tenant, Pdf::make(['Introducción.', 'La garantía de los productos electrónicos es de dos años.']), 'garantia.pdf', 'Garantía');
});

function indexDocumentIn(Tenant $tenant, string $content, string $filename, string $title): Document
{
    return inTenant($tenant, fn () => app(DocumentService::class)->upload($content, $filename, $title));
}

/** @return list<SearchResult> */
function searchKnowledge(string $query, ?Tenant $tenant = null, float $minSimilarity = 0.1, ?int $limit = null): array
{
    return inTenant($tenant ?? test()->tenant, fn () => app(SemanticSearchService::class)->search($query, $limit, $minSimilarity));
}

it('finds the most relevant chunk first with its source', function () {
    $results = searchKnowledge('¿Puedo devolver un producto? ¿Cuántos días tengo?');

    expect($results[0]->documentTitle)->toBe('Política de la tienda')
        ->and($results[0]->content)->toContain('30 días')
        ->and($results[0]->metadata)->toBe(['heading' => 'Devoluciones'])
        ->and($results[0]->source())->toBe('Política de la tienda, sección «Devoluciones»')
        ->and($results[0]->similarity)->toBeGreaterThan(0.1)->toBeLessThanOrEqual(1.0);

    $similarities = array_column(array_map(fn ($r) => (array) $r, $results), 'similarity');
    expect($similarities)->toBe(collect($similarities)->sortDesc()->values()->all());
});

it('cites pdf pages', function () {
    $results = searchKnowledge('garantía productos electrónicos dos años');

    expect($results[0]->documentTitle)->toBe('Garantía')
        ->and($results[0]->source())->toBe('Garantía, pág. 2');
});

it('drops results below the minimum similarity', function () {
    expect(searchKnowledge('factura duplicada cobro tarjeta', minSimilarity: 0.9))->toBe([]);
});

it('respects the limit', function () {
    expect(searchKnowledge('productos días entrega garantía', limit: 2))->toHaveCount(2);
});

it('never returns documentation of another company', function () {
    $globex = Tenant::factory()->create();
    indexDocumentIn($globex, 'SECRETO de Globex: devolver productos en 90 días.', 'globex.txt', 'Globex');

    $acme = searchKnowledge('devolver productos días');
    $other = searchKnowledge('devolver productos días', $globex);

    expect(collect($acme)->pluck('documentTitle')->unique()->all())->not->toContain('Globex')
        ->and(collect($other)->pluck('documentTitle')->unique()->values()->all())->toBe(['Globex']);
});

it('ignores documents that are not ready', function () {
    inTenant($this->tenant, fn () => $this->policy->markFailed('reindexado fallido'));

    expect(collect(searchKnowledge('devolver producto 30 días'))->pluck('documentTitle')->all())->not->toContain('Política de la tienda');
});

it('records the search as an ai request linked to the email', function () {
    $email = inTenant($this->tenant, fn () => Email::factory()->create());

    inTenant($this->tenant, fn () => app(SemanticSearchService::class)->search('devoluciones', email: $email));

    $record = inTenant($this->tenant, fn () => AiRequest::where('purpose', 'knowledge_search')->sole());
    expect($record->email_id)->toBe($email->id);
});

it('does not search with an empty question', function () {
    $calls = count($this->embeddings->calls);

    expect(searchKnowledge('   '))->toBe([])
        ->and($this->embeddings->calls)->toHaveCount($calls);
});

it('uses the configured limit and similarity by default', function () {
    expect(config('ai.rag.search_limit'))->toBe(5)
        ->and(config('ai.rag.min_similarity'))->toBe(0.45);
});
