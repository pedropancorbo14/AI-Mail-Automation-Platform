<?php

use App\AI\Embeddings\EmbeddingProvider;
use App\AI\Exceptions\LLMUnavailableException;
use App\Documents\DocumentService;
use App\Documents\ProcessDocumentJob;
use App\Models\Document;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeEmbeddingProvider;

beforeEach(function () {
    Storage::fake('local');
    config(['ai.rag.min_similarity' => 0.1]);
    $this->embeddings = new FakeEmbeddingProvider();
    app()->instance(EmbeddingProvider::class, $this->embeddings);

    $this->tenant = Tenant::factory()->create();
    $this->admin = User::factory()->for($this->tenant)->admin()->create();
    $this->agent = User::factory()->for($this->tenant)->agent()->create();
});

function policyFile(string $name = 'politica.md', string $content = "# Devoluciones\n\nTienes 30 días para devolver el producto."): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

function documentOf(Tenant $tenant, string $content = 'Envíos en 24-48 horas.', string $filename = 'envios.txt'): Document
{
    return inTenant($tenant, fn () => app(DocumentService::class)->upload($content, $filename, 'Envíos'));
}

it('uploads and indexes a document of the user company', function () {
    $response = $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/documents', ['file' => policyFile(), 'title' => 'Política de devoluciones'])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Política de devoluciones')
        ->assertJsonPath('data.mime_type', 'text/markdown')
        ->assertJsonPath('data.status', 'ready')
        ->assertJsonPath('data.chunks_count', 1)
        ->assertJsonPath('data.uploaded_by', $this->admin->id);

    expect($response->json('data'))->not->toHaveKeys(['path', 'disk', 'sha256'])
        ->and(inTenant($this->tenant, fn () => Document::sole()->tenant_id))->toBe($this->tenant->id);
});

it('returns the document as pending while the queue indexes it', function () {
    Queue::fake();

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/documents', ['file' => policyFile()])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.title', 'politica');

    Queue::assertPushed(ProcessDocumentJob::class);
});

it('explains why a file is rejected', function (UploadedFile $file, string $message) {
    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/documents', ['file' => $file])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file' => $message]);
})->with([
    'word' => fn () => [UploadedFile::fake()->createWithContent('contrato.docx', 'PK'), 'no soportado'],
    'vacío' => fn () => [UploadedFile::fake()->createWithContent('vacio.txt', '   '), 'vacío'],
]);

it('rejects the same file twice', function () {
    $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/documents', ['file' => policyFile()])->assertCreated();

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/documents', ['file' => policyFile('copia.md')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file' => 'ya está subido']);
});

it('lists, shows, reindexes and deletes documents', function () {
    $document = documentOf($this->tenant);

    $this->actingAs($this->agent, 'sanctum')->getJson('/api/v1/documents')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $document->id);
    $this->actingAs($this->agent, 'sanctum')->getJson("/api/v1/documents/{$document->id}")
        ->assertOk()->assertJsonPath('data.status', 'ready');

    Queue::fake();
    $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/documents/{$document->id}/reindex")
        ->assertOk()->assertJsonPath('data.status', 'pending');
    Queue::assertPushed(ProcessDocumentJob::class, fn ($job) => $job->documentId === $document->id);

    $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/v1/documents/{$document->id}")->assertNoContent();
    expect(Document::withoutGlobalScopes()->count())->toBe(0);
});

it('lets agents read and search but not manage', function () {
    $document = documentOf($this->tenant);

    $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/documents', ['file' => policyFile()])->assertForbidden();
    $this->actingAs($this->agent, 'sanctum')->postJson("/api/v1/documents/{$document->id}/reindex")->assertForbidden();
    $this->actingAs($this->agent, 'sanctum')->deleteJson("/api/v1/documents/{$document->id}")->assertForbidden();
    $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/knowledge-base/search', ['query' => 'envíos'])->assertOk();
});

it('searches the documentation with sources', function () {
    documentOf($this->tenant, "# Devoluciones\n\nTienes 30 días para devolver cualquier producto.", 'politica.md');

    $this->actingAs($this->agent, 'sanctum')
        ->postJson('/api/v1/knowledge-base/search', ['query' => '¿Cuántos días tengo para devolver un producto?', 'limit' => 3])
        ->assertOk()
        ->assertJsonPath('data.0.source', 'Envíos, sección «Devoluciones»')
        ->assertJsonPath('data.0.metadata.heading', 'Devoluciones')
        ->assertJsonStructure(['data' => [['source', 'document_id', 'document_title', 'content', 'metadata', 'similarity']]]);
});

it('validates the search', function () {
    $this->actingAs($this->agent, 'sanctum')
        ->postJson('/api/v1/knowledge-base/search', ['query' => '', 'limit' => 50])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['query', 'limit']);
});

it('answers 503 when the embedding model is not available', function () {
    $this->embeddings->failWith = new LLMUnavailableException('No se pudo conectar con Ollama.');

    $this->actingAs($this->agent, 'sanctum')
        ->postJson('/api/v1/knowledge-base/search', ['query' => 'envíos'])
        ->assertStatus(503)
        ->assertJsonPath('message', 'La búsqueda no está disponible en este momento.');
});

// --- Aislamiento -------------------------------------------------------------

it('cannot see, reindex or delete documents of another company', function () {
    $foreign = documentOf(Tenant::factory()->create(), 'SECRETO de Globex', 'globex.txt');

    $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/documents')->assertJsonCount(0, 'data');
    $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/documents/{$foreign->id}")->assertNotFound();
    $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/documents/{$foreign->id}/reindex")->assertNotFound();
    $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/v1/documents/{$foreign->id}")->assertNotFound();

    $results = $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/knowledge-base/search', ['query' => 'SECRETO Globex'])
        ->json('data');

    expect($results)->toBe([]);
});

it('requires authentication', function () {
    $this->getJson('/api/v1/documents')->assertUnauthorized();
    $this->postJson('/api/v1/knowledge-base/search', ['query' => 'x'])->assertUnauthorized();
});
