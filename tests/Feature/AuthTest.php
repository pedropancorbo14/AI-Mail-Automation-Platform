<?php

use App\Models\User;

it('returns the authenticated user via sanctum', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonFragment(['email' => $user->email]);
});

it('rejects unauthenticated requests', function () {
    $this->getJson('/api/v1/user')->assertUnauthorized();
});

it('returns 401 json even without an Accept: application/json header', function () {
    $this->get('/api/v1/user')
        ->assertUnauthorized()
        ->assertJson(['message' => 'No autenticado.']);
});

it('authenticates with a real sanctum bearer token', function () {
    $token = User::factory()->create()->createToken('test')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/user')->assertOk();
});