<?php

use App\Documents\DocumentService;
use App\Models\Document;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    // La búsqueda la hace ai-service (sus casos, en AiServiceApiTest): aquí no encuentra nada.
    Http::fake(['ai-service.test/*' => Http::response(['results' => [], 'calls' => []])]);

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

it('uploads a document of the user company and leaves it indexing in ai-service', function () {
    $response = $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/documents', ['file' => policyFile(), 'title' => 'Política de devoluciones'])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Política de devoluciones')
        ->assertJsonPath('data.mime_type', 'text/markdown')
        ->assertJsonPath('data.status', 'processing')
        ->assertJsonPath('data.uploaded_by', $this->admin->id);

    expect($response->json('data'))->not->toHaveKeys(['path', 'disk', 'sha256'])
        ->and(inTenant($this->tenant, fn () => Document::sole()->tenant_id))->toBe($this->tenant->id);
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
        ->assertOk()->assertJsonPath('data.status', 'processing');

    $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/documents/{$document->id}/reindex")
        ->assertOk()->assertJsonPath('data.status', 'processing');

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

it('validates the search', function () {
    $this->actingAs($this->agent, 'sanctum')
        ->postJson('/api/v1/knowledge-base/search', ['query' => '', 'limit' => 50])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['query', 'limit']);
});

// --- Aislamiento -------------------------------------------------------------

it('cannot see, reindex or delete documents of another company', function () {
    $foreign = documentOf(Tenant::factory()->create(), 'SECRETO de Globex', 'globex.txt');

    $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/documents')->assertJsonCount(0, 'data');
    $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/documents/{$foreign->id}")->assertNotFound();
    $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/documents/{$foreign->id}/reindex")->assertNotFound();
    $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/v1/documents/{$foreign->id}")->assertNotFound();

    // La búsqueda va a ai-service siempre con la empresa del usuario.
    $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/knowledge-base/search', ['query' => 'SECRETO Globex'])->assertOk();
    Http::assertSent(fn ($request) => $request['tenant_id'] === $this->tenant->id);
});

it('requires authentication', function () {
    $this->getJson('/api/v1/documents')->assertUnauthorized();
    $this->postJson('/api/v1/knowledge-base/search', ['query' => 'x'])->assertUnauthorized();
});
