<?php

use App\Models\AuditLog;
use App\Models\CustomerOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Security\Retention\RetentionService;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->admin = User::factory()->for($this->tenant)->admin()->create(['email' => 'admin@empresa.test']);
    $this->agent = User::factory()->for($this->tenant)->agent()->create();
});

function ordersCsv(string $content = "pedido;email;estado;nombre\n45821;ana@cliente.test;enviado;Ana\n45822;luis@cliente.test;entregado;Luis\n"): UploadedFile
{
    return UploadedFile::fake()->createWithContent('pedidos.csv', $content);
}

it('imports a CSV and records who imported what without customer data', function () {
    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/orders/import', ['file' => ordersCsv()])
        ->assertOk()
        ->assertExactJson(['data' => ['rows' => 2, 'created' => 2, 'updated' => 0]]);

    $audit = inTenant($this->tenant, fn () => AuditLog::where('action', 'orders.imported')->sole());
    // jsonb reordena las claves.
    expect($audit->metadata)->toEqualCanonicalizing(['filename' => 'pedidos.csv', 'rows' => 2, 'created' => 2, 'updated' => 0])
        ->and(json_encode($audit->toArray()))->not->toContain('ana@cliente.test');
});

it('returns the errors of each row and imports nothing', function () {
    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/orders/import', ['file' => ordersCsv("pedido;email;estado\n1;ana@cliente.test;volando\n")])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Una fila tiene errores: no se ha importado nada. Corrígela y vuelve a subir el fichero.')
        ->assertJsonPath('errors.file.0', 'Una fila tiene errores: no se ha importado nada. Corrígela y vuelve a subir el fichero.')
        ->assertJsonPath('rows.0.row', 2)
        ->assertJsonPath('total_row_errors', 1);

    expect(inTenant($this->tenant, fn () => CustomerOrder::count()))->toBe(0);
});

it('lists and searches the orders of the company', function () {
    $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/orders/import', ['file' => ordersCsv()])->assertOk();

    $this->actingAs($this->agent, 'sanctum')
        ->getJson('/api/v1/orders?q=LUIS')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.number', '45822')
        ->assertJsonPath('data.0.status', 'delivered')
        ->assertJsonPath('meta.total_orders', 2);

    // "%" es texto, no un comodín.
    $this->actingAs($this->agent, 'sanctum')->getJson('/api/v1/orders?q=%25')->assertOk()->assertJsonCount(0, 'data');
});

it('serves the template', function () {
    $response = $this->actingAs($this->admin, 'sanctum')->get('/api/v1/orders/template')->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('pedidos-plantilla.csv');
});

it('deletes all imported orders', function () {
    $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/orders/import', ['file' => ordersCsv()])->assertOk();

    $this->actingAs($this->admin, 'sanctum')->deleteJson('/api/v1/orders')->assertOk()->assertJsonPath('data.deleted', 2);

    expect(inTenant($this->tenant, fn () => CustomerOrder::count()))->toBe(0)
        ->and(inTenant($this->tenant, fn () => AuditLog::where('action', 'orders.deleted')->sole()->metadata))->toBe(['orders' => 2]);
});

it('lets agents see orders but not import or delete them', function () {
    $this->actingAs($this->agent, 'sanctum')->getJson('/api/v1/orders')->assertOk();
    $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/orders/import', ['file' => ordersCsv()])->assertForbidden();
    $this->actingAs($this->agent, 'sanctum')->deleteJson('/api/v1/orders')->assertForbidden();
    $this->actingAs($this->agent, 'sanctum')->get('/api/v1/orders/template')->assertForbidden();
});

it('never shows or deletes the orders of another company', function () {
    $other = Tenant::factory()->create();
    $otherAdmin = User::factory()->for($other)->admin()->create();
    $this->actingAs($otherAdmin, 'sanctum')->postJson('/api/v1/orders/import', ['file' => ordersCsv()])->assertOk();

    $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/orders')->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($this->admin, 'sanctum')->deleteJson('/api/v1/orders')->assertJsonPath('data.deleted', 0);

    expect(inTenant($other, fn () => CustomerOrder::count()))->toBe(2);
});

it('requires authentication', function () {
    $this->getJson('/api/v1/orders')->assertUnauthorized();
});

it('forgets the imported orders of a person together with their emails', function () {
    $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/orders/import', ['file' => ordersCsv()])->assertOk();

    $this->artisan('data:forget-address', ['tenant' => $this->tenant->id, 'address' => 'ANA@cliente.test'])
        ->expectsOutputToContain('0 emails de ANA@cliente.test y 1 pedidos importados')
        ->assertSuccessful();
    app(RetentionService::class)->forgetAddress($this->tenant, 'ANA@cliente.test');

    expect(inTenant($this->tenant, fn () => CustomerOrder::pluck('customer_email')->all()))->toBe(['luis@cliente.test'])
        ->and(inTenant($this->tenant, fn () => AuditLog::where('action', 'retention.address_forgotten')->sole()->metadata['orders']))->toBe(1);
});
