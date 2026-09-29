<?php

use App\Models\User;

it('revokes the token used in the request', function () {
    $user = User::factory()->create();
    $token = $user->createToken('panel')->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();

    expect($user->tokens()->count())->toBe(0);

    // Sin esto el guard reutiliza el usuario ya resuelto en la petición anterior.
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('keeps the other tokens of the user valid', function () {
    $user = User::factory()->create();
    $panel = $user->createToken('panel')->plainTextToken;
    $crm = $user->createToken('integracion-crm')->plainTextToken;

    $this->withToken($panel)->postJson('/api/v1/auth/logout')->assertNoContent();
    $this->app['auth']->forgetGuards();

    expect($user->tokens()->pluck('name')->all())->toBe(['integracion-crm']);

    $this->withToken($crm)->getJson('/api/v1/auth/me')->assertOk();
});

it('requires authentication', function () {
    $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
});
