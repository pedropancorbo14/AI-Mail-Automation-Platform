<?php

use App\AI\Remote\Contracts\EventStream;
use App\Documents\DocumentService;
use App\Documents\Enums\DocumentStatus;
use App\Documents\Exceptions\DocumentProcessingException;
use App\Models\Document;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/*
 * Documentos de la base de conocimiento: Laravel guarda el original y pide a
 * ai-service que lo indexe (los eventos en detalle, en RemoteDocumentsTest).
 */

beforeEach(function () {
    Storage::fake('local');
    $this->tenant = Tenant::factory()->create();
    $this->streams = app(EventStream::class);
});

const RETURNS_POLICY = "# Devoluciones\n\nTienes 30 días desde la entrega para devolver cualquier producto.";

function uploadDocument(string $content = RETURNS_POLICY, string $filename = 'politica-devoluciones.md', ?string $title = null): Document
{
    return inTenant(test()->tenant, fn () => app(DocumentService::class)->upload($content, $filename, $title)->fresh());
}

it('stores the original privately and asks ai-service to index it', function () {
    $document = uploadDocument(title: 'Política de devoluciones');

    expect($document->status)->toBe(DocumentStatus::Processing)
        ->and($document->title)->toBe('Política de devoluciones')
        ->and($document->mime_type)->toBe('text/markdown')
        ->and($document->original_filename)->toBe('politica-devoluciones.md')
        ->and($document->sha256)->toBe(hash('sha256', RETURNS_POLICY))
        ->and($document->path)->toStartWith("tenants/{$this->tenant->id}/documents/")
        ->and(Storage::disk('local')->get($document->path))->toBe(RETURNS_POLICY)
        ->and($this->streams->payloads('document.uploaded.v1'))->toHaveCount(1);
});

it('uses the file name as title when there is none', function () {
    expect(uploadDocument(filename: 'faq-envios.md')->title)->toBe('faq-envios');
});

it('decides the type by extension, then by the detected type', function (string $filename, ?string $detected, ?string $mime) {
    expect(DocumentService::mimeFor($filename, $detected))->toBe($mime);
})->with([
    'markdown enviado como texto' => ['faq.md', 'text/plain', 'text/markdown'],
    'pdf' => ['manual.PDF', null, 'application/pdf'],
    'alias de markdown' => ['sin-extension', 'text/x-markdown', 'text/markdown'],
    'word' => ['contrato.docx', 'application/msword', null],
]);

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

it('asks ai-service to index it again on reindex', function () {
    $document = uploadDocument();

    inTenant($this->tenant, fn () => app(DocumentService::class)->reindex($document));

    expect($this->streams->payloads('document.uploaded.v1'))->toHaveCount(2)
        ->and($document->fresh()->status)->toBe(DocumentStatus::Processing);
});

it('deletes the file and the document, and tells ai-service to forget its chunks', function () {
    $document = uploadDocument();

    inTenant($this->tenant, fn () => app(DocumentService::class)->delete($document));

    expect(Storage::disk('local')->exists($document->path))->toBeFalse()
        ->and(Document::withoutGlobalScopes()->count())->toBe(0)
        ->and($this->streams->payloads('document.deleted.v1'))->toHaveCount(1);
});

it('records who uploaded it', function () {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $document = inTenant($this->tenant, fn () => app(DocumentService::class)->upload(RETURNS_POLICY, 'p.md', uploader: $user));

    expect($document->uploaded_by)->toBe($user->id);
});
