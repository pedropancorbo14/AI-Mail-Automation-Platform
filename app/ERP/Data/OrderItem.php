<?php

namespace App\ERP\Data;

final readonly class OrderItem
{
    public function __construct(
        public string $sku,
        public string $name,
        public int $quantity,
        /** Precio unitario con impuestos. */
        public float $unitPrice,
    ) {
    }
}
