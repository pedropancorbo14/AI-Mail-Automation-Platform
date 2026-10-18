<?php

use App\AI\Remote\Contracts\EventStream;
use App\AI\Remote\EventContract;
use App\Documents\DocumentService;
use App\Documents\Enums\DocumentStatus;
use App\Documents\ProcessDocumentJob;
use App\Models\AiRequest;
use App\Models\Document;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fakes\InMemoryEventStream;

/*
 * Con el motor remoto el indexado es de ai-service: Laravel guarda el fichero
 * y el estado, publica document.uploaded.v1 con una ruta firmada y aplica
 * document.indexed.v1 / document.indexing_failed.v1.
 */

beforeEach(function () {
    Storage::fake('local');
    Queue::fake([ProcessDocumentJob::class]);
    config(['ai.engine' => 'python']);
    $this->streams = new InMemoryEventStream();
    app()->instance(EventStream::class, $this->streams);
    $this->tenant = Tenant::factory()->create();
    $this->admin = User::factory()->for($this->tenant)->admin()->create();
});

function remoteDocument(Tenant $tenant, string $content = "# Envíos\n\nA Canarias, 14,95 €."): Document
{
    return inTenant($tenant, fn () => app(DocumentService::class)->upload($content, 'envios.md', 'Envíos y entregas'));
}

/** @return array<string, mixed> */
function documentResult(Document $document, string $type = 'document.indexed', array $overrides = []): array
{
    $event = json_decode(file_get_contents(base_path("contracts/events/examples/valid/{$type}.v1.json")), true);

    return array_merge($event, [
        'event_id' => (string) Str::uuid(),
        'tenant_id' => $document->tenant_id,
        'document_id' => $document->id,
        'causation_id' => $document->fresh()->index_event_id,
    ], $overrides);
}

function deliverDocumentResult(array $event, string $type = 'document.indexed'): void
{
    test()->streams->publish("{$type}.v1", ['event_id' => $event['event_id'], 'event_type' => $type, 'payload' => json_encode($event)]);
    test()->artisan('ai:consume-results --once')->assertSuccessful();
}

it('publishes document.uploaded.v1 with a signed path and does not index in Laravel', function () {
    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/documents', ['file' => UploadedFile::fake()->createWithContent('envios.md', "# Envíos\n\nA Canarias, 14,95 €.")])
        ->assertCreated()
        ->assertJsonPath('data.status', 'processing');

    Queue::assertNotPushed(ProcessDocumentJob::class);
    [$event] = $this->streams->payloads('document.uploaded.v1');
    app(EventContract::class)->validate('document.uploaded.v1', $event);
    $document = inTenant($this->tenant, fn () => Document::sole());
    expect($event)->toMatchArray([
        'tenant_id' => $this->tenant->id,
        'document_id' => $document->id,
        'mime_type' => 'text/markdown',
        'sha256' => $document->sha256,
    ])
        ->and($event['download_path'])->toStartWith("/internal/documents/{$document->id}/content?")
        ->and($document->index_event_id)->toBe($event['event_id'])
        ->and($document->index_attempts)->toBe(1);

    // Con la ruta firmada se descarga el original, sin sesión.
    $this->get($event['download_path'])->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
    expect($this->get($event['download_path'])->streamedContent())->toBe("# Envíos\n\nA Canarias, 14,95 €.");
});

it('refuses the content route without a valid signature or with another document', function () {
    $document = remoteDocument($this->tenant);
    $other = remoteDocument($this->tenant, 'Otro documento.');
    [$event] = $this->streams->payloads('document.uploaded.v1');

    $this->get("/internal/documents/{$document->id}/content")->assertForbidden();
    $this->get(str_replace("/{$document->id}/", "/{$other->id}/", $event['download_path']))->assertForbidden();
    $this->travel(31)->minutes();
    $this->get($event['download_path'])->assertForbidden();
});

it('marks the document ready with the result of ai-service and records the embeddings call', function () {
    $document = remoteDocument($this->tenant);

    deliverDocumentResult(documentResult($document));

    $document = $document->fresh();
    expect($document->status)->toBe(DocumentStatus::Ready)
        ->and($document->chunks_count)->toBe(5)
        ->and($document->embedding_model)->toBe('bge-m3');
    $request = AiRequest::withoutGlobalScopes()->sole();
    expect($request->tenant_id)->toBe($this->tenant->id)
        ->and($request->purpose)->toBe('ai_service.document_indexing')
        ->and($request->email_id)->toBeNull();
});

it('shows why a document could not be indexed', function () {
    $document = remoteDocument($this->tenant);

    deliverDocumentResult(documentResult($document, 'document.indexing_failed'), 'document.indexing_failed');

    expect($document->fresh()->status)->toBe(DocumentStatus::Failed)
        ->and($document->fresh()->error)->toContain('PDF no contiene texto');
});

it('ignores a result for a document that is no longer being indexed', function () {
    $document = remoteDocument($this->tenant);
    deliverDocumentResult(documentResult($document));

    deliverDocumentResult(documentResult($document, 'document.indexing_failed'), 'document.indexing_failed');

    expect($document->fresh()->status)->toBe(DocumentStatus::Ready);
});

it('never applies a result to a document of another company', function () {
    $document = remoteDocument($this->tenant);
    $other = Tenant::factory()->create();

    deliverDocumentResult(documentResult($document, overrides: ['tenant_id' => $other->id]));

    expect($document->fresh()->status)->toBe(DocumentStatus::Processing)
        ->and($this->streams->deadLettered)->toHaveCount(1)
        ->and($this->streams->deadLettered[0]['reason'])->toContain('empresa');
});

it('asks ai-service to delete the chunks when a document is deleted, also if it was indexed after', function () {
    $document = remoteDocument($this->tenant);
    $event = documentResult($document);

    $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/v1/documents/{$document->id}")->assertNoContent();
    [$deleted] = $this->streams->payloads('document.deleted.v1');
    app(EventContract::class)->validate('document.deleted.v1', $deleted);
    expect($deleted)->toMatchArray(['tenant_id' => $this->tenant->id, 'document_id' => $document->id]);

    // El resultado llega después de borrarlo: se vuelve a pedir el borrado.
    deliverDocumentResult($event);
    expect($this->streams->payloads('document.deleted.v1'))->toHaveCount(2);
});

it('reindexes through ai-service, from the API and with kb:reindex', function () {
    $document = remoteDocument($this->tenant);
    deliverDocumentResult(documentResult($document));

    $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/documents/{$document->id}/reindex")
        ->assertOk()->assertJsonPath('data.status', 'processing');
    $this->artisan('kb:reindex', ['--tenant' => $this->tenant->id])->expectsOutputToContain('1 documentos')->assertSuccessful();
    $this->artisan('kb:reindex')->assertFailed();

    expect($this->streams->payloads('document.uploaded.v1'))->toHaveCount(3);
});

it('republishes documents stuck indexing and gives up after the maximum attempts', function () {
    $document = remoteDocument($this->tenant);
    $document->forceFill(['index_requested_at' => now()->subHour()])->save();

    $this->artisan('ai:reconcile-remote')->expectsOutputToContain('Documentos republicados: 1')->assertSuccessful();
    expect($this->streams->payloads('document.uploaded.v1'))->toHaveCount(2);

    $document->fresh()->forceFill(['index_requested_at' => now()->subHour(), 'index_attempts' => 3])->save();
    $this->artisan('ai:reconcile-remote')->expectsOutputToContain('Documentos con error: 1');
    expect($document->fresh()->status)->toBe(DocumentStatus::Failed)
        ->and($document->fresh()->error)->toContain('Reindexar');
});
