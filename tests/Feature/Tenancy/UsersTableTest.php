<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('stores the tenant of every user', function () {
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    $this->assertDatabaseHas('users', ['id' => $user->id, 'tenant_id' => $tenant->id]);
});

it('does not allow a user without tenant', function () {
    DB::table('users')->insert([
        'name' => 'Sin empresa',
        'email' => 'sin-empresa@example.com',
        'password' => 'x',
    ]);
})->throws(QueryException::class);

it('does not allow a user pointing to a non existing tenant', function () {
    User::factory()->create(['tenant_id' => 999999]);
})->throws(QueryException::class);

it('does not allow deleting a tenant that still has users', function () {
    $user = User::factory()->create();

    DB::table('tenants')->where('id', $user->tenant_id)->delete();
})->throws(QueryException::class);
