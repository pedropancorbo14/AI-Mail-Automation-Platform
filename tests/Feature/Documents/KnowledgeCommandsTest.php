<?php

use App\Documents\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Tenant;
use Database\Seeders\KnowledgeBaseSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->tenant = Tenant::factory()->create(['slug' => 'empresa-demo']);
});

it('imports a file into a company and searches it through ai-service', function () {
    Http::fake(['ai-service.test/*' => Http::response(['results' => [[
        'source' => 'Envíos, sección «Canarias»', 'chunk_id' => 1, 'document_id' => 1, 'document_title' => 'Envíos',
        'content' => 'A Canarias, 14,95 €.', 'metadata' => ['heading' => 'Canarias'], 'similarity' => 0.81,
    ]], 'calls' => []])]);
    $path = database_path('seeders/knowledge/envios.md');

    $this->artisan('kb:import', ['path' => $path, '--tenant' => $this->tenant->id, '--title' => 'Envíos'])
        ->expectsOutputToContain('«Envíos» subido (processing)')
        ->assertSuccessful();

    $this->artisan('kb:search', ['query' => '¿Cuánto tarda un envío a Canarias?', '--tenant' => $this->tenant->id])
        ->expectsOutputToContain('Envíos, sección «Canarias»')
        ->assertSuccessful();
});

it('explains import errors', function () {
    $this->artisan('kb:import', ['path' => '/no/existe.pdf', '--tenant' => $this->tenant->id])->assertFailed();
    $this->artisan('kb:import', ['path' => database_path('seeders/knowledge/envios.md')])->expectsOutputToContain('--tenant')->assertFailed();

    $this->artisan('kb:import', ['path' => database_path('seeders/knowledge/envios.md'), '--tenant' => $this->tenant->id])->assertSuccessful();
    $this->artisan('kb:import', ['path' => database_path('seeders/knowledge/envios.md'), '--tenant' => $this->tenant->id])
        ->expectsOutputToContain('ya está subido')
        ->assertFailed();
});

it('says when nothing relevant is found', function () {
    Http::fake(['ai-service.test/*' => Http::response(['results' => [], 'calls' => []])]);

    $this->artisan('kb:search', ['query' => 'algo', '--tenant' => $this->tenant->id])
        ->expectsOutputToContain('Sin resultados')
        ->assertSuccessful();
});

it('seeds the demo company with example documentation only once, for ai-service to index', function () {
    $this->seed(KnowledgeBaseSeeder::class);
    $this->seed(KnowledgeBaseSeeder::class);

    $documents = inTenant($this->tenant, fn () => Document::orderBy('title')->get());

    expect($documents->pluck('title')->all())->toBe([
        'Envíos y entregas', 'Política de devoluciones', 'Política de garantía', 'Preguntas frecuentes: pagos y facturas',
    ])->and($documents->pluck('status')->unique()->all())->toBe([DocumentStatus::Processing]);
});
