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