<?php

namespace App\Billing;

use InvalidArgumentException;

/** Catálogo de planes leído de config/plans.php. */
final class PlanCatalog
{
    /** @return list<Plan> */
    public static function all(): array
    {
        $plans = [];

        foreach (config('plans.plans') as $key => $plan) {
            $plans[] = new Plan(
                key: $key,
                name: $plan['name'],
                priceEurMonth: $plan['price_eur_month'],
                description: $plan['description'],
                stripePrice: $plan['stripe_price'] ?? null,
                limits: $plan['limits'] ?? [],
            );
        }

        return $plans;
    }

    public static function find(?string $key): ?Plan
    {
        foreach (self::all() as $plan) {
            if ($plan->key === $key) {
                return $plan;
            }
        }

        return null;
    }

    /** @throws InvalidArgumentException plan que no existe */
    public static function get(string $key): Plan
    {
        return self::find($key) ?? throw new InvalidArgumentException("El plan «{$key}» no existe.");
    }

    /** Plan cuyo precio de Stripe es $price (lo usa el webhook). */
    public static function forStripePrice(string $price): ?Plan
    {
        foreach (self::all() as $plan) {
            if ($plan->stripePrice === $price) {
                return $plan;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(config('plans.plans'));
    }
}
