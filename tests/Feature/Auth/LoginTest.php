<?php

use App\Models\Tenant;
use App\Models\User;

it('logs in with valid credentials and returns a usable token', function () {
    $user = User::factory()->admin()->create(['email' => 'ana@acme.test']);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'ana@acme.test',
        'password' => 'password',
        'device_name' => 'panel',
    ]);

    $response->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.email', 'ana@acme.test')
        ->assertJsonPath('user.role', 'admin')
        ->assertJsonPath('user.tenant.id', $user->tenant_id)
        ->assertJsonMissingPath('user.password');

    expect($user->tokens()->first()->name)->toBe('panel');

    $this->withToken($response->json('token'))
        ->getJson('/api/v1/auth/me')
        ->assertOk();
});

it('rejects a wrong password', function () {
    User::factory()->create(['email' => 'ana@acme.test']);

    $this->postJson('/api/v1/auth/login', ['email' => 'ana@acme.test', 'password' => 'incorrecta'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'Las credenciales no son correctas.');
});

it('gives the same error for an unknown email', function () {
    $this->postJson('/api/v1/auth/login', ['email' => 'nadie@acme.test', 'password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'Las credenciales no son correctas.');
});

it('validates the required fields', function () {
    $this->postJson('/api/v1/auth/login', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password']);
});

it('does not log in users of an inactive tenant', function () {
    $user = User::factory()->for(Tenant::factory()->inactive())->create(['email' => 'ana@acme.test']);

    $this->postJson('/api/v1/auth/login', ['email' => 'ana@acme.test', 'password' => 'password'])
        ->assertForbidden()
        ->assertJsonPath('message', 'La empresa está desactivada.');

    expect($user->tokens()->count())->toBe(0);
});

it('throttles login attempts after 5 per minute', function () {
    User::factory()->create(['email' => 'ana@acme.test']);

    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/auth/login', ['email' => 'ana@acme.test', 'password' => 'incorrecta'])
            ->assertUnprocessable();
    }

    $this->postJson('/api/v1/auth/login', ['email' => 'ana@acme.test', 'password' => 'password'])
        ->assertStatus(429);
});
