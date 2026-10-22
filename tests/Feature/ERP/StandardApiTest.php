<?php

use App\ERP\Csv\OrderCsvImporter;
use App\Models\Tenant;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

const DATA_TOKEN = 'token-de-datos-de-prueba';

beforeEach(function () {
    config(['integrations.data_token' => DATA_TOKEN]);
    $this->tenant = Tenant::factory()->create();
    inTenant($this->tenant, fn () => app(OrderCsvImporter::class)->import(implode("\n", [
        'pedido;email;estado;fecha;total;moneda;productos;transportista;seguimiento;url seguimiento;fecha envio;ciudad;pais',
        '45821;cliente@cliente.test;enviado;28/09/2026;89,90;EUR;"1x Lámpara; 2x Bombilla";SEUR;SEU1;https://seur.test/1;29/09/2026;Zaragoza;ES',
        '44102;cliente@cliente.test;entregado;14/07/2026;34,50;EUR;;;;;;;',
        '76540;marcos@cliente.test;entregado;01/08/2026;349;EUR;;;;;;;',
    ])));
});

/** Errores de un JSON contra los esquemas de contracts/integrations (null = válido). */
function standardApiError(string $schema, mixed $data): ?string
{
    $validator = new Validator;
    $prefix = 'https://contracts.ai-mail-automation.local/integrations/';
    foreach (glob(base_path('contracts/integrations/schemas/*.schema.json')) as $file) {
        $validator->resolver()->registerFile($prefix . basename($file), $file);
    }
    $result = $validator->validate(json_decode(json_encode($data)), "{$prefix}{$schema}.v1.schema.json");

    return $result->isValid() ? null : json_encode((new ErrorFormatter)->format($result->error()));
}

function dataApi(string $path, ?string $token = DATA_TOKEN): Illuminate\Testing\TestResponse
{
    return test()->getJson($path, $token === null ? [] : ['Authorization' => "Bearer {$token}"]);
}

it('validates the shared examples with the same schemas as ai-service', function () {
    foreach (glob(base_path('contracts/integrations/examples/valid/*.json')) as $file) {
        $schema = explode('--', basename($file, '.json'))[0];
        expect(standardApiError($schema, json_decode(file_get_contents($file))))->toBeNull(basename($file));
    }
    foreach (glob(base_path('contracts/integrations/examples/invalid/*.json')) as $file) {
        $schema = explode('--', basename($file, '.json'))[0];
        expect(standardApiError($schema, json_decode(file_get_contents($file))))->not->toBeNull(basename($file));
    }
});

it('serves an order of the customer in the standard format', function () {
    $response = dataApi("/internal/standard-api/{$this->tenant->id}/v1/orders/45821?customer_email=Cliente@Cliente.test")
        ->assertOk()
        ->assertJsonPath('number', '45821')
        ->assertJsonPath('status', 'shipped')
        ->assertJsonPath('total', 89.9)
        ->assertJsonPath('items.1', ['name' => 'Bombilla', 'quantity' => 2])
        ->assertJsonPath('shipment.carrier', 'SEUR')
        ->assertJsonPath('shipping_country', 'ES');

    expect(standardApiError('order', $response->json()))->toBeNull();
});

it('answers 404 for an order of another customer exactly like for one that does not exist', function () {
    $foreign = dataApi("/internal/standard-api/{$this->tenant->id}/v1/orders/76540?customer_email=cliente@cliente.test")->assertNotFound();
    $missing = dataApi("/internal/standard-api/{$this->tenant->id}/v1/orders/99999?customer_email=cliente@cliente.test")->assertNotFound();

    expect($foreign->getContent())->toBe($missing->getContent());
});

it('lists the orders of the customer newest first and within the limit', function () {
    $response = dataApi("/internal/standard-api/{$this->tenant->id}/v1/orders?customer_email=cliente@cliente.test&limit=1")->assertOk();

    expect(collect($response->json('data'))->pluck('number')->all())->toBe(['45821'])
        ->and(standardApiError('order-list', $response->json()))->toBeNull();

    $all = dataApi("/internal/standard-api/{$this->tenant->id}/v1/orders?customer_email=cliente@cliente.test&limit=100")->json('data');
    expect(collect($all)->pluck('number')->all())->toBe(['45821', '44102'])
        ->and(standardApiError('order-list', ['data' => $all]))->toBeNull();

    dataApi("/internal/standard-api/{$this->tenant->id}/v1/orders?customer_email=nadie@cliente.test&limit=5")
        ->assertOk()->assertExactJson(['data' => []]);
});

it('rejects requests without the customer or with a bad limit', function (string $query) {
    dataApi("/internal/standard-api/{$this->tenant->id}/v1/orders?{$query}")->assertStatus(400);
})->with(['customer_email=a@b.test', 'customer_email=a@b.test&limit=0', 'customer_email=a@b.test&limit=101', 'limit=5']);

it('never serves the orders of another company', function () {
    $other = Tenant::factory()->create();

    dataApi("/internal/standard-api/{$other->id}/v1/orders/45821?customer_email=cliente@cliente.test")->assertNotFound();
    dataApi("/internal/standard-api/{$other->id}/v1/orders?customer_email=cliente@cliente.test&limit=5")
        ->assertOk()->assertExactJson(['data' => []]);
});

it('serves nothing for a deactivated company', function () {
    $this->tenant->update(['is_active' => false]);

    dataApi("/internal/standard-api/{$this->tenant->id}/v1/orders/45821?customer_email=cliente@cliente.test")->assertNotFound();
    dataApi("/internal/integrations/{$this->tenant->id}")->assertJsonPath('orders', null);
});

it('tells the MCP server which companies have imported orders', function () {
    $other = Tenant::factory()->create();

    dataApi("/internal/integrations/{$this->tenant->id}")
        ->assertOk()
        ->assertExactJson(['tenant_id' => $this->tenant->id, 'tenant_slug' => $this->tenant->slug, 'orders' => ['type' => 'csv'], 'invoices' => null, 'appointments' => null, 'tickets' => null]);
    dataApi("/internal/integrations/{$other->id}")->assertJsonPath('orders', null);
});

it('hides every internal data route without the right token', function (?string $token, ?string $configured) {
    config(['integrations.data_token' => $configured]);

    dataApi("/internal/integrations/{$this->tenant->id}", $token)->assertNotFound();
    dataApi("/internal/standard-api/{$this->tenant->id}/v1/orders/45821?customer_email=cliente@cliente.test", $token)->assertNotFound();
})->with([
    'sin token' => [null, DATA_TOKEN],
    'token incorrecto' => ['otro', DATA_TOKEN],
    'sin token configurado' => ['', null],
]);
