<?php

use App\AI\Embeddings\EmbeddingProvider;
use App\AI\Exceptions\LLMConfigurationException;
use App\AI\Exceptions\LLMUnavailableException;
use App\Documents\DocumentService;
use App\Documents\Enums\DocumentStatus;
use App\Documents\Exceptions\DocumentProcessingException;
use App\Documents\ProcessDocumentJob;
use App\Models\AiRequest;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeEmbeddingProvider;
use Tests\Support\Pdf;

beforeEach(function () {
    Storage::fake('local');
    $this->tenant = Tenant::factory()->create();
    $this->embeddings = new FakeEmbeddingProvider();
    app()->instance(EmbeddingProvider::class, $this->embeddings);
});

const RETURNS_POLICY = "# Devoluciones\n\nTienes 30 días desde la entrega para devolver cualquier producto.\n\n# Productos dañados\n\nSi llega roto, envía fotos y te mandamos otro sin coste.";

/** Sube un documento dentro de la empresa de prueba (con la cola sync se indexa al momento). */
function uploadDocument(string $content = RETURNS_POLICY, string $filename = 'politica-devoluciones.md', ?string $title = null): Document
{
    return inTenant(test()->tenant, fn () => app(DocumentService::class)->upload($content, $filename, $title)->fresh());
}

function documentChunks(Document $document)
{
    return inTenant(test()->tenant, fn () => $document->chunks()->get());
}

it('stores the original privately and indexes it into chunks with embeddings', function () {
    $document = uploadDocument(title: 'Política de devoluciones');

    expect($document->status)->toBe(DocumentStatus::Ready)
        ->and($document->title)->toBe('Política de devoluciones')
        ->and($document->mime_type)->toBe('text/markdown')
        ->and($document->original_filename)->toBe('politica-devoluciones.md')
        ->and($document->sha256)->toBe(hash('sha256', RETURNS_POLICY))
        ->and($document->path)->toStartWith("tenants/{$this->tenant->id}/documents/")
        ->and(Storage::disk('local')->get($document->path))->toBe(RETURNS_POLICY)
        ->and($document->chunks_count)->toBe(2)
        ->and($document->embedding_model)->toBe('bge-m3')
        ->and($document->processed_at)->not->toBeNull();

    $chunks = documentChunks($document);
    expect($chunks->pluck('metadata')->all())->toBe([['heading' => 'Devoluciones'], ['heading' => 'Productos dañados']])
        ->and($chunks[0]->content)->toStartWith('# Devoluciones')
        ->and($chunks[0]->embedding)->toHaveCount(1024)
        ->and($chunks[0]->tenant_id)->toBe($this->tenant->id);
});

it('embeds each chunk with its document title and section as context', function () {
    uploadDocument(title: 'Política de devoluciones');

    expect($this->embeddings->calls[0]['texts'][1])
        ->toStartWith("Documento: Política de devoluciones\nSección: Productos dañados\n\n# Productos dañados");
});

it('uses the file name as title when there is none', function () {
    expect(uploadDocument(filename: 'faq-envios.md')->title)->toBe('faq-envios');
});

it('indexes pdfs page by page', function () {
    $document = uploadDocument(Pdf::make(['Envíos en 24-48 horas.', 'Canarias: 3 a 5 días.']), 'envios.pdf');

    expect(documentChunks($document)->pluck('metadata')->all())->toBe([['page' => 1], ['page' => 2]]);
});

it('records the embedding call in ai_requests', function () {
    uploadDocument();

    $record = inTenant($this->tenant, fn () => AiRequest::sole());
    expect($record->purpose)->toBe('document_indexing')
        ->and($record->cost_usd)->toBe('0.000000');
});

it('queues the indexing on the documents queue', function () {
    Queue::fake();

    $document = uploadDocument();

    expect($document->status)->toBe(DocumentStatus::Pending);
    Queue::assertPushedOn('documents', ProcessDocumentJob::class, fn ($job) => $job->documentId === $document->id && $job->tenantId === $this->tenant->id);
});

it('runs long jobs on a queue connection that does not relaunch them', function () {
    $job = new ProcessDocumentJob(1, 1);

    expect($job->timeout)->toBeLessThan(config('queue.connections.redis-long.retry_after'))
        ->and(config('horizon.defaults.supervisor-documents.connection'))->toBe('redis-long')
        ->and(config('horizon.defaults.supervisor-documents.timeout'))->toBeGreaterThan($job->timeout);
});

it('rejects unsupported, empty, too big and repeated documents', function (Closure $upload, string $message) {
    expect($upload)->toThrow(DocumentProcessingException::class, $message);
})->with([
    'word' => [fn () => uploadDocument('PK', 'contrato.docx'), 'no soportado'],
    'vacío' => [fn () => uploadDocument("  \n", 'vacio.txt'), 'vacío'],
    'grande' => [function () {
        config(['ai.rag.max_upload_mb' => 1]);
        uploadDocument(str_repeat('a', 1024 * 1024 + 1), 'enorme.txt');
    }, 'tamaño máximo'],
    'repetido' => [function () {
        uploadDocument();
        uploadDocument(filename: 'copia.md');
    }, 'ya está subido'],
]);

it('marks documents without text as failed without retrying', function () {
    $document = uploadDocument(Pdf::make(['']), 'escaneado.pdf');

    expect($document->status)->toBe(DocumentStatus::Failed)
        ->and($document->error)->toContain('escaneado')
        ->and($this->embeddings->calls)->toBe([]);
});

it('marks the document as failed when the embedding model is not available', function () {
    $this->embeddings->failWith = new LLMConfigurationException('El modelo bge-m3 no está descargado en Ollama.');

    expect(uploadDocument()->status)->toBe(DocumentStatus::Failed);
});

it('leaves temporary errors to the queue and fails after the last retry', function () {
    $this->embeddings->failWith = new LLMUnavailableException('No se pudo conectar con Ollama.');
    Queue::fake();
    $document = uploadDocument();

    $job = (new ProcessDocumentJob($this->tenant->id, $document->id))->withFakeQueueInteractions();
    expect(fn () => app()->call([$job, 'handle']))->toThrow(LLMUnavailableException::class);
    expect(inTenant($this->tenant, fn () => $document->fresh()->status))->toBe(DocumentStatus::Processing);

    $job->failed(new LLMUnavailableException('No se pudo conectar con Ollama.'));
    expect(inTenant($this->tenant, fn () => $document->fresh()))
        ->status->toBe(DocumentStatus::Failed)
        ->error->toBe('No se pudo conectar con Ollama.');
});

it('reindexes replacing the previous chunks', function () {
    $document = uploadDocument();
    $before = documentChunks($document)->pluck('id')->all();

    inTenant($this->tenant, fn () => app(DocumentService::class)->process($document));

    $after = documentChunks($document)->pluck('id')->all();
    expect($after)->toHaveCount(2)->and(array_intersect($before, $after))->toBe([]);
});

it('deletes the file, the chunks and the document', function () {
    $document = uploadDocument();

    inTenant($this->tenant, fn () => app(DocumentService::class)->delete($document));

    expect(Storage::disk('local')->exists($document->path))->toBeFalse()
        ->and(Document::withoutGlobalScopes()->count())->toBe(0)
        ->and(DocumentChunk::withoutGlobalScopes()->count())->toBe(0);
});

it('records who uploaded it', function () {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $document = inTenant($this->tenant, fn () => app(DocumentService::class)->upload(RETURNS_POLICY, 'p.md', uploader: $user));

    expect($document->uploaded_by)->toBe($user->id);
});
