<?php

use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Route;

it('checks the role of a user', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->hasRole('admin'))->toBeTrue()
        ->and($admin->hasRole('owner', 'admin'))->toBeTrue()
        ->and($admin->hasRole('owner'))->toBeFalse();
});

it('checks permissions through the role', function () {
    $owner = User::factory()->owner()->create();
    $admin = User::factory()->admin()->create();
    $agent = User::factory()->agent()->create();

    expect($owner->hasPermission(Permission::TENANT_UPDATE))->toBeTrue()
        ->and($admin->hasPermission(Permission::USERS_CREATE))->toBeTrue()
        ->and($admin->hasPermission(Permission::TENANT_UPDATE))->toBeFalse()
        ->and($agent->hasPermission(Permission::USERS_VIEW))->toBeTrue()
        ->and($agent->hasPermission(Permission::USERS_DELETE))->toBeFalse()
        ->and($agent->hasPermission('permiso.inventado'))->toBeFalse();
});

it('exposes every permission as a gate', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->can(Permission::USERS_DELETE))->toBeTrue()
        ->and($admin->can(Permission::TENANT_UPDATE))->toBeFalse();
});

it('protects routes with the can middleware', function () {
    Route::middleware(['api', 'auth:sanctum', 'tenant', 'can:' . Permission::USERS_CREATE])
        ->get('/api/v1/_test/solo-crear-usuarios', fn () => ['ok' => true]);

    $this->actingAs(User::factory()->agent()->create(), 'sanctum')
        ->getJson('/api/v1/_test/solo-crear-usuarios')
        ->assertForbidden();

    $this->actingAs(User::factory()->admin()->create(), 'sanctum')
        ->getJson('/api/v1/_test/solo-crear-usuarios')
        ->assertOk();
});

it('returns the permissions of the user in /auth/me', function () {
    $this->actingAs(User::factory()->agent()->create(), 'sanctum')
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.permissions', [Permission::DOCUMENTS_VIEW, Permission::EMAILS_REVIEW, Permission::EMAILS_VIEW, Permission::MAIL_CONNECTIONS_VIEW, Permission::TENANT_VIEW, Permission::USERS_VIEW]);
});
