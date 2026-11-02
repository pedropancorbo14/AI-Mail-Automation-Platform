<?php

namespace App\Billing;

/** Uso de una empresa frente a los límites de su plan. */
final readonly class Usage
{
    /**
     * @param  array<string, int|float>  $used  por PlanLimit::value
     */
    public function __construct(
        public Plan $plan,
        public string $period,
        private array $used,
        public int $emailsReceived,
    ) {
    }

    public function used(PlanLimit $limit): int|float
    {
        return $this->used[$limit->value];
    }

    /** Lo que queda (null = sin límite; nunca negativo). */
    public function remaining(PlanLimit $limit): int|float|null
    {
        $max = $this->plan->limit($limit);

        return $max === null ? null : max(0, $max - $this->used($limit));
    }

    public function reached(PlanLimit $limit): bool
    {
        $remaining = $this->remaining($limit);

        return $remaining !== null && $remaining <= 0;
    }

    /** Fracción usada (0–1+) o null sin límite. */
    public function ratio(PlanLimit $limit): ?float
    {
        $max = $this->plan->limit($limit);

        if ($max === null) {
            return null;
        }

        return $max <= 0 ? 1.0 : $this->used($limit) / $max;
    }

    /** @return array<string, array{used: int|float, limit: int|float|null, ratio: float|null}> */
    public function toArray(): array
    {
        $rows = [];

        foreach (PlanLimit::cases() as $limit) {
            $rows[$limit->value] = [
                'used' => $this->used($limit),
                'limit' => $this->plan->limit($limit),
                'ratio' => ($ratio = $this->ratio($limit)) === null ? null : round($ratio, 4),
            ];
        }

        return $rows;
    }
}
