<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;

it('seeds the role catalog', function () {
    expect(Role::pluck('name')->sort()->values()->all())
        ->toBe([Role::ADMIN, Role::AGENT, Role::OWNER]);
});

it('can run the seeder twice without duplicating roles', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Role::count())->toBe(3);
});

it('gives users the agent role by default', function () {
    expect(User::factory()->create()->role->name)->toBe(Role::AGENT);
});

it('assigns the requested role', function () {
    expect(User::factory()->owner()->create()->role->name)->toBe(Role::OWNER)
        ->and(User::factory()->admin()->create()->role->name)->toBe(Role::ADMIN);
});

it('does not allow deleting a role that is in use', function () {
    $user = User::factory()->admin()->create();

    $user->role->delete();
})->throws(QueryException::class);
