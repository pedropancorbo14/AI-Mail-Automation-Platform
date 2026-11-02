<?php

namespace App\Billing;

/** Un plan del catálogo (config/plans.php). */
final readonly class Plan
{
    /** @param array<string, int|float|null> $limits */
    public function __construct(
        public string $key,
        public string $name,
        public ?int $priceEurMonth,
        public string $description,
        public ?string $stripePrice,
        private array $limits,
    ) {
    }

    /** Límite del plan, o null si no tiene. */
    public function limit(PlanLimit $limit): int|float|null
    {
        return $this->limits[$limit->value] ?? null;
    }

    /** Se puede contratar desde el panel (tiene precio en Stripe). */
    public function purchasable(): bool
    {
        return $this->stripePrice !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'price_eur_month' => $this->priceEurMonth,
            'description' => $this->description,
            'limits' => array_combine(
                array_map(fn (PlanLimit $l) => $l->value, PlanLimit::cases()),
                array_map(fn (PlanLimit $l) => $this->limit($l), PlanLimit::cases()),
            ),
        ];
    }
}
