<?php

use App\Documents\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Exceptions\MissingTenantException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
});

it('has the expected columns', function () {
    expect(Schema::hasColumns('documents', [
        'id', 'tenant_id', 'uploaded_by', 'title', 'original_filename', 'mime_type', 'size', 'disk', 'path',
        'sha256', 'status', 'error', 'chunks_count', 'embedding_model', 'processed_at', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('starts pending, without chunks and without exposing the file path', function () {
    $document = inTenant($this->tenant, fn () => Document::factory()->create()->fresh());

    expect($document->status)->toBe(DocumentStatus::Pending)
        ->and($document->chunks_count)->toBe(0)
        ->and($document->tenant_id)->toBe($this->tenant->id)
        ->and($document->toArray())->not->toHaveKeys(['disk', 'path']);
});

it('moves through processing, ready and failed', function () {
    $this->freezeSecond();
    $document = inTenant($this->tenant, fn () => Document::factory()->create());

    inTenant($this->tenant, fn () => $document->markProcessing());
    expect($document->fresh()->status)->toBe(DocumentStatus::Processing);

    inTenant($this->tenant, fn () => $document->markReady(12, 'bge-m3'));
    $ready = $document->fresh();
    expect($ready->status)->toBe(DocumentStatus::Ready)
        ->and($ready->chunks_count)->toBe(12)
        ->and($ready->embedding_model)->toBe('bge-m3')
        ->and($ready->processed_at->eq(now()))->toBeTrue();

    inTenant($this->tenant, fn () => $document->markFailed(str_repeat('x', 3000)));
    $failed = $document->fresh();
    expect($failed->status)->toBe(DocumentStatus::Failed)
        ->and(mb_strlen($failed->error))->toBeLessThanOrEqual(1003);
});

it('does not store the same file twice in a company, but allows it in another', function () {
    inTenant($this->tenant, fn () => Document::factory()->create(['sha256' => str_repeat('a', 64)]));
    inTenant(Tenant::factory()->create(), fn () => Document::factory()->create(['sha256' => str_repeat('a', 64)]));

    expect(Document::withoutGlobalScopes()->count())->toBe(2);

    inTenant($this->tenant, fn () => Document::factory()->create(['sha256' => str_repeat('a', 64)]));
})->throws(QueryException::class);

it('keeps the document when its uploader is deleted', function () {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $document = inTenant($this->tenant, fn () => Document::factory()->create(['uploaded_by' => $user->id]));

    $user->delete();

    expect(inTenant($this->tenant, fn () => $document->fresh()->uploaded_by))->toBeNull();
});

it('only mass assigns the title', function () {
    $document = new Document(['title' => 'Política', 'status' => 'ready', 'path' => '../../etc/passwd', 'tenant_id' => 9]);

    expect($document->title)->toBe('Política')
        ->and($document->status)->toBe(DocumentStatus::Pending)
        ->and($document->path)->toBeNull()
        ->and($document->tenant_id)->toBeNull();
});

it('isolates documents by company and fails closed without tenant', function () {
    inTenant($this->tenant, fn () => Document::factory()->create());

    expect(inTenant(Tenant::factory()->create(), fn () => Document::count()))->toBe(0);

    Document::count();
})->throws(MissingTenantException::class);
