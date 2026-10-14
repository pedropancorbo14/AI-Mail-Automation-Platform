<?php

use App\Models\AiToolCall;
use App\Models\Email;
use App\Models\Tenant;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'empresa-demo']);
    enableTools($this->tenant, 'get_order', 'get_customer_orders');
    $this->email = inTenant($this->tenant, fn () => Email::factory()->create(['from_email' => 'cliente@cliente.test']));
});

it('lists the available tools', function () {
    $this->artisan('ai:tool', ['--list' => true])
        ->expectsOutputToContain('get_order')
        ->expectsOutputToContain('get_customer_orders')
        ->expectsOutputToContain('search_company_documents')
        ->assertSuccessful();
});

it('runs a tool as if the ai processed an email', function () {
    $this->artisan('ai:tool', ['name' => 'get_order', '--email' => $this->email->id, '--args' => '{"order_number":"45821"}'])
        ->expectsOutputToContain('"tracking_number": "SEU123456789ES"')
        ->assertSuccessful();

    expect(inTenant($this->tenant, fn () => AiToolCall::sole()->email_id))->toBe($this->email->id);
});

it('shows the error the ai would get', function () {
    $this->artisan('ai:tool', ['name' => 'get_order', '--email' => $this->email->id, '--args' => '{"order_number":"76540"}'])
        ->expectsOutputToContain('No hay ningún pedido con ese número asociado a este cliente')
        ->assertFailed();
});

it('runs tools that do not need an email with a company', function () {
    $this->artisan('ai:tool', ['name' => 'get_customer_orders', '--tenant' => $this->tenant->id])
        ->expectsOutputToContain('solo puede usarse al procesar un email')
        ->assertFailed();
});

it('validates its own options', function () {
    $this->artisan('ai:tool', ['name' => 'get_order', '--email' => $this->email->id, '--args' => 'no es json'])->assertFailed();
    $this->artisan('ai:tool', ['name' => 'get_order', '--args' => '{}'])->expectsOutputToContain('--email')->assertFailed();
});
