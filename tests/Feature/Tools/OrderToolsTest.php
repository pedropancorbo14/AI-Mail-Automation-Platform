<?php

use App\Models\AiToolCall;
use App\Models\Email;
use App\Models\Tenant;
use App\Tools\Enums\ToolCallStatus;
use App\Tools\ToolContext;
use App\Tools\ToolExecutor;
use App\Tools\ToolRegistry;
use App\Tools\ToolResult;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'empresa-demo']);
});

/** Ejecuta una herramienta como si la IA procesara un email de $sender. */
function toolForSender(string $tool, array $arguments, ?string $sender = 'cliente@cliente.test', ?Tenant $tenant = null): ToolResult
{
    $tenant ??= test()->tenant;
    $email = $sender === null ? null : inTenant($tenant, fn () => Email::factory()->create(['from_email' => $sender]));

    return app(ToolExecutor::class)->execute($tool, $arguments, new ToolContext($tenant, $email));
}

it('offers the order tools to the ai', function () {
    expect(app(ToolRegistry::class)->has('get_order'))->toBeTrue()
        ->and(app(ToolRegistry::class)->has('get_customer_orders'))->toBeTrue();
});

// --- get_order ---------------------------------------------------------------

it('gives the sender the detail of their order with tracking', function () {
    $order = toolForSender('get_order', ['order_number' => '#45821'])->toArray();

    expect($order)->toMatchArray([
        'order_number' => '45821',
        'status' => 'shipped',
        'status_label' => 'Enviado',
        'total' => 89.9,
        'currency' => 'EUR',
        'customer_name' => 'Cliente Pérez',
        'shipping_city' => 'Zaragoza',
    ])
        ->and($order['items'][0]['name'])->toBe('Lámpara de mesa Nórdica')
        ->and($order['shipment']['carrier'])->toBe('SEUR')
        ->and($order['shipment']['tracking_number'])->toBe('SEU123456789ES')
        ->and($order['shipment']['delivered_at'])->toBeNull()
        ->and($order['shipment']['estimated_delivery'])->toStartWith('2026-10-01');
});

it('accepts the order number written in different ways', function (string $written) {
    expect(toolForSender('get_order', ['order_number' => $written])->ok)->toBeTrue();
})->with(['45821', '#45821', ' 45821 ', 'nº 45821', 'pedido 45821']);

it('answers the same for orders of other customers and orders that do not exist', function () {
    $foreign = toolForSender('get_order', ['order_number' => '76540']);   // existe, pero es de Marcos
    $missing = toolForSender('get_order', ['order_number' => '99999']);

    expect($foreign->ok)->toBeFalse()
        ->and($foreign->toArray())->toBe($missing->toArray())
        ->and($foreign->error)->toBe('No hay ningún pedido con ese número asociado a este cliente.')
        ->and(json_encode($foreign->toArray()))->not->toContain('Marcos');
});

it('matches the sender regardless of capital letters', function () {
    expect(toolForSender('get_order', ['order_number' => '76540'], 'MARCOS@Cliente.test')->ok)->toBeTrue();
});

it('cannot be used without an email sender', function () {
    $result = toolForSender('get_order', ['order_number' => '45821'], sender: null);

    expect($result->error)->toContain('solo puede usarse al procesar un email')
        ->and(inTenant($this->tenant, fn () => AiToolCall::sole()->status))->toBe(ToolCallStatus::Rejected);
});

it('never shows orders of another company', function () {
    $other = Tenant::factory()->create(['slug' => 'globex']);

    expect(toolForSender('get_order', ['order_number' => '45821'], tenant: $other)->ok)->toBeFalse();
});

// --- get_customer_orders -----------------------------------------------------

it('lists only the orders of the sender, newest first', function () {
    $result = toolForSender('get_customer_orders', [], 'laura@cliente.test')->toArray();

    expect(array_column($result['orders'], 'order_number'))->toBe(['78123', '74400'])
        ->and($result['orders'][0]['status_label'])->toBe('En preparación')
        ->and($result['orders'][1]['status'])->toBe('cancelled')
        ->and($result['orders'][0])->not->toHaveKey('items');
});

it('does not accept another email as argument', function () {
    $result = toolForSender('get_customer_orders', ['email' => 'marcos@cliente.test'], 'laura@cliente.test');

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toContain('Argumentos no válidos')
        ->and(json_encode($result->toArray()))->not->toContain('76540');
});

it('respects the limit and says when there are no orders', function () {
    expect(toolForSender('get_customer_orders', ['limit' => 1])->toArray()['orders'])->toHaveCount(1);

    $none = toolForSender('get_customer_orders', [], 'nuevo@cliente.test')->toArray();
    expect($none['orders'])->toBe([])
        ->and($none['note'])->toContain('no tiene pedidos');
});

it('records every order lookup for the audit', function () {
    toolForSender('get_order', ['order_number' => '45821']);
    toolForSender('get_order', ['order_number' => '76540']);

    $calls = inTenant($this->tenant, fn () => AiToolCall::orderBy('id')->get());
    expect($calls->pluck('status')->all())->toBe([ToolCallStatus::Success, ToolCallStatus::Error])
        ->and($calls[1]->arguments)->toBe(['order_number' => '76540']);
});
