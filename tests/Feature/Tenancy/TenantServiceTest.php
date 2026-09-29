<?php

use App\Models\Tenant;
use App\Tenancy\Exceptions\MissingTenantException;
use App\Tenancy\TenantService;

it('starts without tenant', function () {
    $service = app(TenantService::class);

    expect($service->check())->toBeFalse()
        ->and($service->current())->toBeNull()
        ->and($service->id())->toBeNull();
});

it('stores the current tenant', function () {
    $tenant = Tenant::factory()->create();
    $service = app(TenantService::class);

    $service->set($tenant);

    expect($service->check())->toBeTrue()
        ->and($service->id())->toBe($tenant->id)
        ->and(app(TenantService::class)->current()->is($tenant))->toBeTrue();
});

it('fails when a tenant is required and there is none', function () {
    app(TenantService::class)->currentOrFail();
})->throws(MissingTenantException::class);

it('runs a callback as another tenant and restores the previous one', function () {
    [$acme, $globex] = Tenant::factory()->count(2)->create();
    $service = app(TenantService::class);
    $service->set($acme);

    $seen = $service->runAs($globex, fn (Tenant $tenant) => $service->id());

    expect($seen)->toBe($globex->id)
        ->and($service->id())->toBe($acme->id);
});

it('restores the previous tenant even if the callback fails', function () {
    $service = app(TenantService::class);

    try {
        $service->runAs(Tenant::factory()->create(), fn () => throw new RuntimeException());
    } catch (RuntimeException) {
    }

    expect($service->check())->toBeFalse();
});

it('does not leak the tenant between requests or jobs', function () {
    app(TenantService::class)->set(Tenant::factory()->create());

    // Lo que hace Laravel al terminar cada petición / job de cola.
    app()->forgetScopedInstances();

    expect(app(TenantService::class)->check())->toBeFalse();
});
