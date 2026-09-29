<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;

it('returns the authenticated user with tenant and role', function () {
    $user = User::factory()->owner()->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonPath('data.role', 'owner')
        ->assertJsonPath('data.tenant.id', $user->tenant_id)
        ->assertJsonMissingPath('data.password');
});

it('rejects unauthenticated requests', function () {
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('returns 401 json even without an Accept: application/json header', function () {
    $this->get('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertJson(['message' => 'No autenticado.']);
});

it('authenticates with a real sanctum bearer token', function () {
    $token = User::factory()->create()->createToken('test')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
});

it('rejects an invalid token', function () {
    $this->withToken('1|token-inventado')->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('rejects expired tokens', function () {
    $token = User::factory()->create()->createToken('test')->plainTextToken;

    $this->travel(config('sanctum.expiration') + 1)->minutes();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('schedules the daily cleanup of expired tokens', function () {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toContain('sanctum:prune-expired');
});
