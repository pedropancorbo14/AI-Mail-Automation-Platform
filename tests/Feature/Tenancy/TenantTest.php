<?php

use App\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;

it('creates an active tenant by default', function () {
    $tenant = Tenant::factory()->create();

    expect($tenant->is_active)->toBeTrue();
    $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'slug' => $tenant->slug]);
});

it('does not allow two tenants with the same slug', function () {
    Tenant::factory()->create(['slug' => 'acme']);

    Tenant::factory()->create(['slug' => 'acme']);
})->throws(UniqueConstraintViolationException::class);
