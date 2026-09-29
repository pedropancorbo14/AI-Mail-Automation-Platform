<?php

use App\Models\Tenant;
use App\Models\User;

it('lists only the users of its own tenant', function () {
    $acme = Tenant::factory()->create();
    $globex = Tenant::factory()->create();

    $acmeUsers = User::factory()->count(2)->for($acme)->create();
    User::factory()->for($globex)->create();

    expect($acme->users)->toHaveCount(2)
        ->and($acme->users->pluck('id')->sort()->values()->all())
        ->toBe($acmeUsers->pluck('id')->sort()->values()->all());
});

it('knows the tenant of a user', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->for($tenant)->create();

    expect($user->tenant->is($tenant))->toBeTrue();
});

it('creates users through the tenant relation', function () {
    $tenant = Tenant::factory()->create();

    $user = $tenant->users()->create([
        'name' => 'Ana',
        'email' => 'ana@example.com',
        'password' => 'secret-password',
    ]);

    expect($user->tenant_id)->toBe($tenant->id);
});
