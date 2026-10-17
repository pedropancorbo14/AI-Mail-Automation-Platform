<?php

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;

function permissionsOf(string $role): array
{
    return Role::where('name', $role)->firstOrFail()
        ->permissions->pluck('name')->sort()->values()->all();
}

it('seeds the permission catalog', function () {
    expect(Permission::count())->toBe(count(Permission::CATALOG));
});

it('gives the owner every permission', function () {
    expect(permissionsOf(Role::OWNER))
        ->toBe(Permission::pluck('name')->sort()->values()->all());
});

it('does not let the admin edit the tenant', function () {
    expect(permissionsOf(Role::ADMIN))
        ->toContain(Permission::USERS_CREATE, Permission::USERS_DELETE)
        ->not->toContain(Permission::TENANT_UPDATE);
});

it('gives the agent read only permissions plus reviewing emails', function () {
    expect(permissionsOf(Role::AGENT))
        ->toBe([Permission::DOCUMENTS_VIEW, Permission::EMAILS_REVIEW, Permission::EMAILS_VIEW, Permission::MAIL_CONNECTIONS_VIEW, Permission::TENANT_VIEW, Permission::USERS_VIEW]);
});

it('can run the seeder twice without duplicating permissions', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Permission::count())->toBe(count(Permission::CATALOG))
        ->and(permissionsOf(Role::AGENT))->toHaveCount(count(Role::PERMISSIONS[Role::AGENT]));
});
