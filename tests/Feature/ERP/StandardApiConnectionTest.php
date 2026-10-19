<?php

use App\ERP\Csv\OrderCsvImporter;
use App\ERP\StandardApi\StandardApiTester;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const ERP_URL = 'https://erp.empresa.test/aimail';
const ERP_KEY = 'clave-secreta-del-erp';

beforeEach(function () {
    // "erp.empresa.test" no resuelve en los tests: hosts privados permitidos
    // (los casos de anti-SSRF lo desactivan).
    config(['mail_connections.allow_private_hosts' => true, 'integrations.data_token' => 'token-de-datos']);
    Http::preventStrayRequests();

    $this->tenant = Tenant::factory()->create();
    $this->admin = User::factory()->for($this->tenant)->admin()->create(['email' => 'admin@empresa.test']);
    $this->agent = User::factory()->for($this->tenant)->agent()->create();
});

function fakeErp(int $status = 200, mixed $body = ['data' => []]): void
{
    Http::fake(['erp.empresa.test/*' => Http::response($body, $status)]);
}

function connectErp(array $overrides = []): Illuminate\Testing\TestResponse
{
    return test()->actingAs(test()->admin, 'sanctum')->putJson('/api/v1/integrations/standard-api', [
        'base_url' => ERP_URL,
        'token' => ERP_KEY,
        'orders' => true,
        'invoices' => false,
        'enabled' => true,
        ...$overrides,
    ]);
}

it('connects the company system, tests it at once and never returns the key', function () {
    fakeErp();

    $response = connectErp()
        ->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.has_token', true)
        ->assertJsonPath('result.ok', true);

    expect(json_encode($response->json()))->not->toContain(ERP_KEY);
    Http::assertSent(fn (Request $r) => $r->url() === ERP_URL . '/v1/orders?customer_email=' . urlencode(StandardApiTester::PROBE_EMAIL) . '&limit=1'
        && $r->hasHeader('Authorization', 'Bearer ' . ERP_KEY));

    // Cifrada en la base de datos y fuera de la auditoría.
    $raw = DB::table('tenant_integrations')->value('credentials');
    expect($raw)->not->toContain(ERP_KEY)
        ->and(inTenant($this->tenant, fn () => TenantIntegration::sole()->credentials))->toBe(ERP_KEY)
        ->and(inTenant($this->tenant, fn () => AuditLog::pluck('action')->all()))->toBe(['integration.created', 'integration.tested'])
        ->and(json_encode(inTenant($this->tenant, fn () => AuditLog::get()->toArray())))->not->toContain(ERP_KEY);
});

it('tests the invoices too when the system has them', function () {
    fakeErp();

    connectErp(['invoices' => true])->assertJsonPath('result.ok', true);

    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), ERP_URL . '/v1/invoices?'));
});

it('explains why the connection fails and keeps it as failing', function (int $status, mixed $body, string $message) {
    fakeErp($status, $body);

    connectErp()
        ->assertOk()
        ->assertJsonPath('data.status', 'failing')
        ->assertJsonPath('result.ok', false)
        ->assertJsonPath('data.last_error', fn (string $error) => str_contains($error, $message));
})->with([
    'clave' => [401, [], 'rechazó la clave'],
    'sin ruta' => [404, [], 'no tiene la ruta /v1/orders'],
    'caído' => [503, [], 'respondió 503'],
    'formato' => [200, ['orders' => []], 'no responde con el formato de la API estándar'],
    'pedido mal' => [200, ['data' => [['number' => '1', 'customer_email' => 'x', 'status' => 'enviado']]], 'formato'],
]);

it('says so when the system cannot be reached', function () {
    Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('timeout'));

    connectErp()->assertJsonPath('result.error', fn (string $e) => str_contains($e, 'no responde'));
});

it('refuses addresses that point inside the platform or carry secrets', function (string $url, bool $allowPrivate, string $message) {
    config(['mail_connections.allow_private_hosts' => $allowPrivate]);

    connectErp(['base_url' => $url])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['base_url' => $message]);

    expect(inTenant($this->tenant, fn () => TenantIntegration::count()))->toBe(0);
    Http::assertNothingSent();
})->with([
    'metadatos de la nube (siempre)' => ['https://169.254.169.254/latest', true, 'dirección interna'],
    'loopback' => ['https://127.0.0.1/aimail', false, 'dirección interna'],
    'red privada' => ['https://10.0.0.5/aimail', false, 'dirección interna'],
    'sin TLS' => ['http://93.184.215.14/aimail', false, 'https://'],
    'usuario en la URL' => ['https://user:pass@erp.empresa.test/aimail', true, 'usuario, contraseña'],
    'parámetros' => ['https://erp.empresa.test/aimail?key=1', true, 'parámetros'],
    'no es una URL' => ['erp.empresa.test', true, 'no es válida'],
]);

it('keeps the saved key when the edit leaves it empty', function () {
    fakeErp();
    connectErp();

    connectErp(['token' => null, 'invoices' => true])->assertOk();

    expect(inTenant($this->tenant, fn () => TenantIntegration::sole()->credentials))->toBe(ERP_KEY);
    $changes = inTenant($this->tenant, fn () => AuditLog::where('action', 'integration.updated')->sole()->changes);
    expect($changes)->toHaveKey('invoices')->not->toHaveKey('token');
});

it('requires the key the first time', function () {
    connectErp(['token' => null])->assertUnprocessable()->assertJsonValidationErrors(['token']);
});

it('tests and deletes the connection', function () {
    fakeErp();
    connectErp();

    $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/integrations/standard-api/test')->assertOk()->assertJsonPath('result.ok', true);
    $this->actingAs($this->admin, 'sanctum')->deleteJson('/api/v1/integrations/standard-api')->assertOk();

    $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/integrations/standard-api')->assertOk()->assertExactJson(['data' => null]);
    $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/integrations/standard-api/test')->assertNotFound();
});

it('lets agents see the connection but not change it', function () {
    fakeErp();
    connectErp();

    $this->actingAs($this->agent, 'sanctum')->getJson('/api/v1/integrations/standard-api')->assertOk()->assertJsonPath('data.base_url', ERP_URL);
    $this->actingAs($this->agent, 'sanctum')->putJson('/api/v1/integrations/standard-api', ['base_url' => ERP_URL, 'orders' => true, 'invoices' => false, 'enabled' => true])->assertForbidden();
    $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/integrations/standard-api/test')->assertForbidden();
    $this->actingAs($this->agent, 'sanctum')->deleteJson('/api/v1/integrations/standard-api')->assertForbidden();
});

it('never shows the connection of another company', function () {
    fakeErp();
    connectErp();
    $other = User::factory()->for(Tenant::factory())->admin()->create();

    $this->actingAs($other, 'sanctum')->getJson('/api/v1/integrations/standard-api')->assertExactJson(['data' => null]);
    $this->actingAs($other, 'sanctum')->deleteJson('/api/v1/integrations/standard-api')->assertNotFound();
});

it('gives the MCP server the company system, which wins over the imported orders', function () {
    fakeErp();
    inTenant($this->tenant, fn () => app(OrderCsvImporter::class)->import("pedido;email;estado\n1;a@b.test;enviado\n"));
    connectErp(['invoices' => true]);

    $response = $this->getJson("/internal/integrations/{$this->tenant->id}", ['Authorization' => 'Bearer token-de-datos'])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');

    $own = ['type' => 'standard_api', 'base_url' => ERP_URL, 'token' => ERP_KEY];
    expect($response->json('orders'))->toBe($own)->and($response->json('invoices'))->toBe($own);

    // Desactivada: vuelven los pedidos importados y no hay facturas.
    connectErp(['enabled' => false, 'invoices' => true]);
    $this->getJson("/internal/integrations/{$this->tenant->id}", ['Authorization' => 'Bearer token-de-datos'])
        ->assertJsonPath('orders', ['type' => 'csv'])
        ->assertJsonPath('invoices', null);
});
