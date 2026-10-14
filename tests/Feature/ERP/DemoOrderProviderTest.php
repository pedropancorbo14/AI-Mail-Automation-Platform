<?php

use App\ERP\Contracts\OrderProvider;
use App\ERP\Enums\OrderStatus;
use App\ERP\Providers\DemoOrderProvider;
use App\Models\Tenant;
use App\Tenancy\Exceptions\MissingTenantException;
use App\Tenancy\TenantService;
use App\Tools\ToolRegistry;

beforeEach(function () {
    $this->demo = Tenant::factory()->create(['slug' => 'empresa-demo']);
});

function demoOrders(Tenant $tenant, Closure $callback): mixed
{
    return inTenant($tenant, fn () => $callback(app(OrderProvider::class)));
}

it('finds a demo order with its items and shipment', function () {
    $order = demoOrders($this->demo, fn (OrderProvider $orders) => $orders->findOrder('#45821'));

    expect($order->number)->toBe('45821')
        ->and($order->customerEmail)->toBe('cliente@cliente.test')
        ->and($order->status)->toBe(OrderStatus::Shipped)
        ->and($order->total)->toBe(89.9)
        ->and($order->items)->toHaveCount(2)
        ->and($order->items[0]->name)->toBe('Lámpara de mesa Nórdica')
        ->and($order->shipment->carrier)->toBe('SEUR')
        ->and($order->shipment->trackingNumber)->toBe('SEU123456789ES')
        ->and($order->shipment->deliveredAt)->toBeNull()
        ->and($order->shippingCity)->toBe('Zaragoza');
});

it('returns null for an order that does not exist', function () {
    expect(demoOrders($this->demo, fn (OrderProvider $orders) => $orders->findOrder('99999')))->toBeNull();
});

it('lists the orders of a customer, newest first', function () {
    $orders = demoOrders($this->demo, fn (OrderProvider $orders) => $orders->ordersForCustomer(' Cliente@Cliente.TEST '));

    expect(array_map(fn ($o) => $o->number, $orders))->toBe(['45821', '44102'])
        ->and(demoOrders($this->demo, fn (OrderProvider $orders) => $orders->ordersForCustomer('cliente@cliente.test', 1)))->toHaveCount(1)
        ->and(demoOrders($this->demo, fn (OrderProvider $orders) => $orders->ordersForCustomer('nadie@x.test')))->toBe([]);
});

it('only gives orders to the company they belong to', function () {
    $other = Tenant::factory()->create(['slug' => 'globex']);

    expect(demoOrders($other, fn (OrderProvider $orders) => $orders->findOrder('45821')))->toBeNull()
        ->and(demoOrders($other, fn (OrderProvider $orders) => $orders->ordersForCustomer('cliente@cliente.test')))->toBe([]);
});

it('needs a company in context', function () {
    app(OrderProvider::class)->findOrder('45821');
})->throws(MissingTenantException::class);

it('never keeps the company of a previous job in long running workers', function () {
    $first = app(OrderProvider::class);
    $registry = app(ToolRegistry::class);

    // Lo que hace el worker entre jobs: TenantService es scoped y se reinicia.
    app()->forgetScopedInstances();

    expect(app(OrderProvider::class))->not->toBe($first)
        ->and(app(ToolRegistry::class))->not->toBe($registry)
        ->and(app(TenantService::class)->check())->toBeFalse();
});

it('explains a missing demo file', function () {
    $provider = new DemoOrderProvider(app(TenantService::class), '/no/existe.json');

    inTenant($this->demo, fn () => $provider->findOrder('1'));
})->throws(RuntimeException::class, 'No se pueden leer los pedidos');
