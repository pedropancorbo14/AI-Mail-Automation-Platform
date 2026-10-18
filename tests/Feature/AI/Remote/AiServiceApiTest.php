<?php

use App\Models\AiRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Tools\TenantToolService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * Con el motor remoto, lo que el panel necesita al momento (búsqueda en la
 * documentación, herramientas disponibles) se pide a la API interna de ai-service.
 */

beforeEach(function () {
    config([
        'ai.remote.service_url' => 'http://ai-service:8000',
        'ai.remote.internal_token' => 'internal-secret',
    ]);
    Http::preventStrayRequests();
    $this->tenant = Tenant::factory()->create(['slug' => 'empresa-demo']);
    $this->admin = User::factory()->admin()->for($this->tenant)->create();
});

function searchResponse(): array
{
    return [
        'results' => [[
            'source' => 'Envíos y entregas, sección «Coste del envío»',
            'chunk_id' => 7,
            'document_id' => 3,
            'document_title' => 'Envíos y entregas',
            'content' => 'A Canarias, 14,95 €.',
            'metadata' => ['heading' => 'Coste del envío'],
            'similarity' => 0.71,
        ]],
        'calls' => [[
            'purpose' => 'ai_service.knowledge_search', 'provider' => 'ollama',
            'requested_model' => 'bge-m3', 'model' => 'bge-m3', 'status' => 'success',
            'input_tokens' => 9, 'output_tokens' => 0, 'cache_write_tokens' => 0, 'cache_read_tokens' => 0,
            'latency_ms' => 40, 'stop_reason' => null, 'provider_request_id' => null, 'error' => null,
        ]],
    ];
}

it('searches the documentation through ai-service for the company of the user', function () {
    Http::fake(['ai-service:8000/internal/v1/knowledge/search' => Http::response(searchResponse())]);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/knowledge-base/search', ['query' => '¿Enviáis a Canarias?', 'limit' => 3])
        ->assertOk()
        ->assertJsonPath('data.0.source', 'Envíos y entregas, sección «Coste del envío»')
        ->assertJsonPath('data.0.document_id', 3)
        ->assertJsonPath('data.0.similarity', 0.71);

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer internal-secret')
        // La empresa la pone Laravel (la del usuario), nunca el cliente.
        && $request['tenant_id'] === $this->tenant->id
        && $request['query'] === '¿Enviáis a Canarias?'
        && $request['limit'] === 3);
    // La llamada de embeddings queda registrada como las demás.
    $request = AiRequest::withoutGlobalScopes()->sole();
    expect($request->tenant_id)->toBe($this->tenant->id)
        ->and($request->purpose)->toBe('ai_service.knowledge_search');
});

it('answers 503 when ai-service does not answer or fails', function () {
    Http::fake(['ai-service:8000/*' => Http::response(['detail' => 'Ollama caído'], 503)]);
    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/knowledge-base/search', ['query' => 'envíos'])
        ->assertStatus(503)
        ->assertJsonPath('message', 'La búsqueda no está disponible en este momento.');

    config(['ai.remote.internal_token' => null]);
    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/knowledge-base/search', ['query' => 'envíos'])
        ->assertStatus(503);
});

it('lists the catalog tools with what the company enabled and what has data', function () {
    Http::fake(['ai-service:8000/internal/v1/tools*' => Http::response(['tenant_id' => 1, 'tools' => [
        ['name' => 'get_order', 'available' => true],
        ['name' => 'get_invoice', 'available' => false],
        ['name' => 'search_company_documents', 'available' => true],
    ]])]);
    app(TenantToolService::class)->enable($this->tenant, 'get_order');

    $tools = collect($this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/automation')->assertOk()->json('data.tools'))
        ->keyBy('tool');

    expect($tools->keys()->all())->toContain('get_customer_tickets', 'create_support_ticket', 'get_invoice')
        ->and($tools['get_order'])->toMatchArray(['enabled' => true, 'available' => true, 'source' => 'company'])
        ->and($tools['search_company_documents'])->toMatchArray(['enabled' => true, 'source' => 'default'])
        ->and($tools['get_invoice'])->toMatchArray(['enabled' => false, 'available' => false])
        // ai-service no dijo nada de ella: desconocida.
        ->and($tools['get_customer_tickets']['available'])->toBeNull();
    Http::assertSent(fn (Request $request) => str_contains($request->url(), "tenant_id={$this->tenant->id}"));
});

it('shows availability as unknown when ai-service is down, and tools can still be enabled', function () {
    Http::fake(['ai-service:8000/*' => Http::failedConnection()]);

    $tools = collect($this->actingAs($this->admin, 'sanctum')
        ->patchJson('/api/v1/automation/tools/get_customer_invoices', ['enabled' => true])
        ->assertOk()->json('data.tools'))->keyBy('tool');

    expect($tools['get_customer_invoices'])->toMatchArray(['enabled' => true, 'available' => null]);
});
