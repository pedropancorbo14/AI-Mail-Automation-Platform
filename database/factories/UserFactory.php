<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            // Los roles son un catálogo fijo (RolesAndPermissionsSeeder), no se fabrican.
            'role_id' => fn () => Role::where('name', Role::AGENT)->value('id'),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function owner(): static
    {
        return $this->withRole(Role::OWNER);
    }

    public function admin(): static
    {
        return $this->withRole(Role::ADMIN);
    }

    public function agent(): static
    {
        return $this->withRole(Role::AGENT);
    }

    private function withRole(string $name): static
    {
        return $this->state(fn (array $attributes) => [
            'role_id' => Role::where('name', $name)->value('id'),
        ]);
    }
}
