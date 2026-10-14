<?php

namespace App\ERP\Data;

use App\ERP\Enums\OrderStatus;
use Carbon\CarbonImmutable;

/** Pedido tal como lo ve la plataforma, venga del sistema que venga. */
final readonly class Order
{
    /** @param  list<OrderItem>  $items */
    public function __construct(
        public string $number,
        public string $customerEmail,
        public ?string $customerName,
        public OrderStatus $status,
        public CarbonImmutable $placedAt,
        public float $total,
        public string $currency,
        public array $items,
        public ?Shipment $shipment = null,
        /** Ciudad y país de entrega (sin la dirección completa: no hace falta para responder). */
        public ?string $shippingCity = null,
        public ?string $shippingCountry = null,
    ) {
    }

    /** ¿Es de este cliente? (comparación sin mayúsculas ni espacios) */
    public function belongsTo(string $email): bool
    {
        return mb_strtolower(trim($this->customerEmail)) === mb_strtolower(trim($email));
    }
}
