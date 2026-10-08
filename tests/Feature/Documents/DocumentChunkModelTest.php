<?php

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fakes\FakeEmbeddingProvider;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->document = inTenant($this->tenant, fn () => Document::factory()->create());
    $this->embeddings = new FakeEmbeddingProvider();
});

function addChunk(int $position, string $content): DocumentChunk
{
    return inTenant(test()->tenant, fn () => test()->document->chunks()->create([
        'position' => $position,
        'content' => $content,
        'token_count' => 10,
        'metadata' => ['page' => $position + 1],
        'embedding' => test()->embeddings->vector($content),
    ]));
}

it('has the expected columns and a 1024 dimension vector', function () {
    expect(Schema::hasColumns('document_chunks', [
        'id', 'tenant_id', 'document_id', 'position', 'content', 'token_count', 'metadata', 'embedding', 'created_at',
    ]))->toBeTrue()
        ->and(DB::scalar("select format_type(atttypid, atttypmod) from pg_attribute where attrelid = 'document_chunks'::regclass and attname = 'embedding'"))
        ->toBe('vector(1024)');
});

it('has an hnsw cosine index on the embedding', function () {
    $index = DB::scalar("select indexdef from pg_indexes where tablename = 'document_chunks' and indexdef like '%hnsw%'");

    expect($index)->toContain('USING hnsw')->toContain('vector_cosine_ops');
});

it('stores the chunk with its vector in the document company', function () {
    $chunk = addChunk(0, 'Puedes devolver el producto en 30 días.');
    $stored = inTenant($this->tenant, fn () => DocumentChunk::with('document')->find($chunk->id));

    expect($stored->tenant_id)->toBe($this->tenant->id)
        ->and($stored->embedding)->toHaveCount(1024)
        ->and(round(array_sum(array_map(fn ($v) => $v * $v, $stored->embedding)), 5))->toBe(1.0)
        ->and($stored->metadata)->toBe(['page' => 1])
        ->and($stored->document->id)->toBe($this->document->id)
        ->and($stored->toArray())->not->toHaveKey('embedding');
});

it('finds the closest chunk by cosine distance', function () {
    addChunk(0, 'Política de devoluciones: puedes devolver cualquier producto en 30 días.');
    addChunk(1, 'Envíos: los pedidos llegan en 24 o 48 horas laborables.');
    addChunk(2, 'Garantía: dos años en todos los productos electrónicos.');

    $query = $this->embeddings->vector('¿Cómo puedo devolver un producto?');
    $best = inTenant($this->tenant, fn () => DocumentChunk::query()->orderByVectorDistance('embedding', $query)->first());

    expect($best->position)->toBe(0);
});

it('keeps the chunk order unique inside a document', function () {
    addChunk(0, 'a');
    addChunk(0, 'b');
})->throws(QueryException::class);

it('rejects vectors with other dimensions', function () {
    inTenant($this->tenant, fn () => $this->document->chunks()->create([
        'position' => 0, 'content' => 'x', 'embedding' => [0.6, 0.8],
    ]));
})->throws(QueryException::class);

it('deletes the chunks together with their document', function () {
    addChunk(0, 'a');
    addChunk(1, 'b');

    inTenant($this->tenant, fn () => $this->document->delete());

    expect(DocumentChunk::withoutGlobalScopes()->count())->toBe(0);
});

it('isolates chunks by company', function () {
    addChunk(0, 'a');

    expect(inTenant(Tenant::factory()->create(), fn () => DocumentChunk::count()))->toBe(0);
});
