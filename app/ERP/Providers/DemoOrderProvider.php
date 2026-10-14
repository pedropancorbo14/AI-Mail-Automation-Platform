<?php

namespace App\ERP\Providers;

use App\ERP\Contracts\OrderProvider;
use App\ERP\Data\Order;
use App\ERP\Data\OrderItem;
use App\ERP\Data\Shipment;
use App\ERP\Enums\OrderStatus;
use App\Tenancy\TenantService;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Pedidos de demostración leídos de un JSON ({slug de empresa: [pedidos]}),
 * para probar las herramientas y el agente sin un ERP real. Cada empresa
 * solo ve los suyos (por el slug del tenant actual); las que no estén en el
 * fichero no tienen pedidos. Se sustituye por integraciones reales en la Fase 14.
 */
class DemoOrderProvider implements OrderProvider
{
    /** @var array<string, list<Order>>|null */
    private ?array $orders = null;

    public function __construct(
        private readonly TenantService $tenants,
        private readonly string $file,
    ) {
    }

    public function findOrder(string $number): ?Order
    {
        $number = ltrim(trim($number), '#');

        foreach ($this->tenantOrders() as $order) {
            if ($order->number === $number) {
                return $order;
            }
        }

        return null;
    }

    public function ordersForCustomer(string $email, int $limit = 10): array
    {
        $orders = array_values(array_filter($this->tenantOrders(), fn (Order $order) => $order->belongsTo($email)));
        usort($orders, fn (Order $a, Order $b) => $b->placedAt <=> $a->placedAt);

        return array_slice($orders, 0, max(0, $limit));
    }

    /** @return list<Order> */
    private function tenantOrders(): array
    {
        $this->orders ??= $this->load();

        return $this->orders[$this->tenants->currentOrFail()->slug] ?? [];
    }

    /** @return array<string, list<Order>> */
    private function load(): array
    {
        $data = is_file($this->file) ? json_decode((string) file_get_contents($this->file), true) : null;

        if (! is_array($data)) {
            throw new RuntimeException("No se pueden leer los pedidos de demostración ({$this->file}).");
        }

        return array_map(fn (array $orders) => array_map(self::order(...), $orders), $data);
    }

    /** @param  array<string, mixed>  $row */
    private static function order(array $row): Order
    {
        $date = fn (?string $value) => $value ? CarbonImmutable::parse($value) : null;
        $shipment = $row['shipment'] ?? null;

        return new Order(
            number: (string) $row['number'],
            customerEmail: $row['customer_email'],
            customerName: $row['customer_name'] ?? null,
            status: OrderStatus::from($row['status']),
            placedAt: CarbonImmutable::parse($row['placed_at']),
            total: (float) $row['total'],
            currency: $row['currency'],
            items: array_map(fn (array $item) => new OrderItem($item['sku'], $item['name'], (int) $item['quantity'], (float) $item['unit_price']), $row['items']),
            shipment: $shipment ? new Shipment(
                carrier: $shipment['carrier'],
                trackingNumber: $shipment['tracking_number'] ?? null,
                trackingUrl: $shipment['tracking_url'] ?? null,
                shippedAt: $date($shipment['shipped_at'] ?? null),
                estimatedDelivery: $date($shipment['estimated_delivery'] ?? null),
                deliveredAt: $date($shipment['delivered_at'] ?? null),
            ) : null,
            shippingCity: $row['shipping_city'] ?? null,
            shippingCountry: $row['shipping_country'] ?? null,
        );
    }
}
