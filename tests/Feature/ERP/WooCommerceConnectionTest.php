<?php

use App\ERP\Csv\OrderCsvImporter;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const SHOP_URL = 'https://tienda.empresa.test';
const SHOP_CK = 'ck_0123456789abcdef';
const SHOP_CS = 'cs_fedcba9876543210';

beforeEach(function () {
    config(['mail_connections.allow_private_hosts' => true, 'integrations.data_token' => 'token-de-datos']);
    Http::preventStrayRequests();

    $this->tenant = Tenant::factory()->create();
    $this->admin = User::factory()->for($this->tenant)->admin()->create(['email' => 'admin@empresa.test']);
});

function fakeShop(int $status = 200, mixed $body = [['id' => 1, 'number' => '1001', 'status' => 'processing']]): void
{
    Http::fake(['tienda.empresa.test/*' => Http::response($body, $status)]);
}

function connectShop(array $overrides = []): Illuminate\Testing\TestResponse
{
    return test()->actingAs(test()->admin, 'sanctum')->putJson('/api/v1/integrations/woocommerce', [
        'base_url' => SHOP_URL,
        'consumer_key' => SHOP_CK,
        'consumer_secret' => SHOP_CS,
        'enabled' => true,
        ...$overrides,
    ]);
}

it('connects a WooCommerce shop with its API keys and never returns the secret', function () {
    fakeShop();

    $response = connectShop()
        ->assertOk()
        ->assertJsonPath('data.type', 'woocommerce')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.orders', true)
        ->assertJsonPath('data.invoices', false)
        ->assertJsonPath('data.consumer_key_hint', '…cdef');

    expect(json_encode($response->json()))->not->toContain(SHOP_CS)->not->toContain(SHOP_CK);
    Http::assertSent(fn (Request $r) => $r->url() === SHOP_URL . '/wp-json/wc/v3/orders?per_page=1'
        && $r->hasHeader('Authorization', 'Basic ' . base64_encode(SHOP_CK . ':' . SHOP_CS)));

    expect(DB::table('tenant_integrations')->value('credentials'))->not->toContain(SHOP_CS)
        ->and(json_encode(inTenant($this->tenant, fn () => AuditLog::get()->toArray())))->not->toContain(SHOP_CS);
});

it('explains why the shop cannot be connected', function (int $status, mixed $body, string $message) {
    fakeShop($status, $body);

    connectShop()
        ->assertJsonPath('data.status', 'failing')
        ->assertJsonPath('result.error', fn (string $error) => str_contains($error, $message));
})->with([
    'claves' => [401, ['code' => 'woocommerce_rest_cannot_view'], 'rechazó las claves de API'],
    'no es WooCommerce' => [404, ['code' => 'rest_no_route'], 'enlaces permanentes'],
    'caída' => [500, [], 'respondió 500'],
    'otra cosa' => [200, ['code' => 'algo'], 'no responde como la API de pedidos'],
]);

it('refuses shop addresses inside the platform', function () {
    connectShop(['base_url' => 'https://169.254.169.254'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['base_url' => 'dirección interna']);

    Http::assertNothingSent();
});

it('requires both keys the first time and keeps them when the edit leaves them empty', function () {
    connectShop(['consumer_secret' => null])->assertUnprocessable()->assertJsonValidationErrors(['consumer_secret']);

    fakeShop();
    connectShop()->assertOk();
    connectShop(['consumer_key' => null, 'consumer_secret' => null, 'enabled' => false])->assertOk()->assertJsonPath('data.enabled', false);

    expect(json_decode(inTenant($this->tenant, fn () => TenantIntegration::sole()->credentials), true))
        ->toBe(['consumer_key' => SHOP_CK, 'consumer_secret' => SHOP_CS]);
});

it('gives the MCP server the shop for orders, after the own system and before the CSV', function () {
    fakeShop();
    $config = fn () => $this->getJson("/internal/integrations/{$this->tenant->id}", ['Authorization' => 'Bearer token-de-datos']);
    inTenant($this->tenant, fn () => app(OrderCsvImporter::class)->import("pedido;email;estado\n1;a@b.test;enviado\n"));

    connectShop();
    $config()->assertJsonPath('orders', [
        'type' => 'woocommerce', 'base_url' => SHOP_URL, 'consumer_key' => SHOP_CK, 'consumer_secret' => SHOP_CS,
    ])->assertJsonPath('invoices', null);

    // Con el ERP propio conectado (pedidos), manda el ERP.
    Http::fake(['erp.empresa.test/*' => Http::response(['data' => []])]);
    $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/integrations/standard-api', [
        'base_url' => 'https://erp.empresa.test/aimail', 'token' => 'clave', 'orders' => true, 'invoices' => false, 'enabled' => true,
    ])->assertOk();
    $config()->assertJsonPath('orders.type', 'standard_api');

    // Sin ninguno de los dos, vuelve el CSV.
    $this->actingAs($this->admin, 'sanctum')->deleteJson('/api/v1/integrations/standard-api')->assertOk();
    $this->actingAs($this->admin, 'sanctum')->deleteJson('/api/v1/integrations/woocommerce')->assertOk();
    $config()->assertJsonPath('orders', ['type' => 'csv']);
});

it('rejects unknown kinds of integration', function () {
    $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/integrations/shopify')->assertNotFound();
});
