<?php

namespace App\ERP\Data;

use Carbon\CarbonImmutable;

final readonly class Shipment
{
    public function __construct(
        public string $carrier,
        public ?string $trackingNumber = null,
        public ?string $trackingUrl = null,
        public ?CarbonImmutable $shippedAt = null,
        public ?CarbonImmutable $estimatedDelivery = null,
        public ?CarbonImmutable $deliveredAt = null,
    ) {
    }
}
