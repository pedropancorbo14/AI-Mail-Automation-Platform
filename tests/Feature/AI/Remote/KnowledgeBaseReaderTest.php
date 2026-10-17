<?php

use App\Documents\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Rol de solo lectura de ai-service (contracts/database/knowledge_base_reader.sql):
 * se comprueba dentro de la transacción del test con SET LOCAL ROLE (el
 * usuario de los tests es superusuario y puede adoptar el rol), así RLS y los
 * permisos se aplican igual que a ai-service.
 */

beforeEach(function () {
    $this->acme = Tenant::factory()->create();
    $this->globex = Tenant::factory()->create();

    foreach ([$this->acme, $this->globex] as $tenant) {
        inTenant($tenant, function () use ($tenant) {
            $document = Document::factory()->create(['title' => "Política de {$tenant->id}"]);
            $document->forceFill(['status' => DocumentStatus::Ready, 'embedding_model' => 'bge-m3'])->save();
            $document->chunks()->create([
                'position' => 0,
                'content' => "Texto de la empresa {$tenant->id}",
                'token_count' => 5,
                'metadata' => ['heading' => 'Plazos'],
                'embedding' => array_fill(0, 1024, 1 / 32),
            ]);
        });
    }
});

/** Ejecuta $sql como ai_service_reader (y con app.tenant_id si se indica). */
function asReader(string $sql, ?int $tenantId = null): array
{
    DB::statement('SAVEPOINT reader');
    try {
        DB::statement('SET LOCAL ROLE ai_service_reader');
        if ($tenantId !== null) {
            DB::statement("SELECT set_config('app.tenant_id', ?, true)", [(string) $tenantId]);
        }

        return DB::select($sql);
    } finally {
        DB::statement('ROLLBACK TO SAVEPOINT reader');
        DB::statement('RESET ROLE');
    }
}

it('sees nothing without a tenant in the session (fails closed)', function () {
    expect(asReader('SELECT id FROM documents'))->toBe([])
        ->and(asReader('SELECT id FROM document_chunks'))->toBe([]);
});

it('only sees the rows of the tenant set in the session', function () {
    $rows = asReader('SELECT tenant_id, content FROM document_chunks', $this->acme->id);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->tenant_id)->toBe($this->acme->id)
        ->and(asReader("SELECT id FROM document_chunks WHERE tenant_id = {$this->globex->id}", $this->acme->id))->toBe([])
        ->and(asReader('SELECT title FROM documents', $this->globex->id)[0]->title)->toBe("Política de {$this->globex->id}");
});

it('can search by vector distance within its tenant', function () {
    $vector = '[' . implode(',', array_fill(0, 1024, 1 / 32)) . ']';

    $rows = asReader("SELECT c.id, 1 - (c.embedding <=> '{$vector}'::vector) AS similarity
        FROM document_chunks c JOIN documents d ON d.id = c.document_id
        WHERE d.status = 'ready' ORDER BY c.embedding <=> '{$vector}'::vector LIMIT 5", $this->acme->id);

    expect($rows)->toHaveCount(1)->and(round((float) $rows[0]->similarity, 3))->toBe(1.0);
});

it('cannot read columns it does not need', function (string $sql) {
    asReader($sql, $this->acme->id);
})->with([
    'ruta del fichero' => ['SELECT path FROM documents'],
    'hash' => ['SELECT sha256 FROM documents'],
    'quién lo subió' => ['SELECT uploaded_by FROM documents'],
    'otras tablas' => ['SELECT id FROM emails'],
])->throws(QueryException::class, 'permission denied');

it('cannot write anything', function (string $sql) {
    asReader($sql, $this->acme->id);
})->with([
    'insertar' => ["INSERT INTO documents (tenant_id, title) VALUES (1, 'x')"],
    'actualizar' => ["UPDATE document_chunks SET content = 'x'"],
    'borrar' => ['DELETE FROM document_chunks'],
])->throws(QueryException::class, 'permission denied');

it('does not change what Laravel sees (RLS does not apply to the owner)', function () {
    expect(DB::table('document_chunks')->count())->toBe(2);
});

it('keeps the columns ai-service reads (shared database contract)', function () {
    $columns = fn (string $table) => collect(DB::select(
        'SELECT column_name FROM information_schema.columns WHERE table_name = ?', [$table]
    ))->pluck('column_name')->all();

    expect($columns('documents'))->toContain('id', 'tenant_id', 'title', 'status', 'embedding_model')
        ->and($columns('document_chunks'))->toContain('id', 'tenant_id', 'document_id', 'position', 'content', 'metadata', 'embedding');
});
