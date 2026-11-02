<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'is_active' => true,
            // Sin límites de plan por defecto: los tests de planes lo cambian
            // con ->onPlan(). Así un test cualquiera no choca con un límite.
            'plan' => 'enterprise',
            'subscription_status' => 'active',
        ];
    }

    public function onPlan(string $plan, string $status = 'active'): static
    {
        return $this->state(fn (array $attributes) => [
            'plan' => $plan,
            'subscription_status' => $status,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
