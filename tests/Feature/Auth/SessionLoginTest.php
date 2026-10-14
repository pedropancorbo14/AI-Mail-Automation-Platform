<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/*
 * Login del panel con cookie de sesión (Sanctum SPA). Las peticiones del
 * panel llevan el Origin de un dominio "stateful": así Sanctum les abre sesión.
 */

beforeEach(function () {
    config(['sanctum.stateful' => ['panel.test']]);
    RateLimiter::clear('ana@acme.test|127.0.0.1');
    $this->user = User::factory()->admin()->create(['email' => 'ana@acme.test']);
});

function fromPanel(): Tests\TestCase
{
    return test()->withHeaders(['Origin' => 'http://panel.test', 'Referer' => 'http://panel.test/']);
}

it('logs into the panel with a session cookie, without a token', function () {
    fromPanel()->postJson('/api/v1/auth/session', ['email' => 'ana@acme.test', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('data.email', 'ana@acme.test')
        ->assertJsonPath('data.role', 'admin')
        ->assertJsonPath('data.tenant.id', $this->user->tenant_id)
        ->assertJsonMissingPath('token')
        ->assertJsonPath('data.permissions', fn (array $permissions) => in_array('users.view', $permissions, true));

    $this->assertAuthenticatedAs($this->user, 'web');
    expect($this->user->tokens()->count())->toBe(0);

    // La sesión sirve para el resto de la API.
    fromPanel()->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', 'ana@acme.test');
});

it('logs out of the panel', function () {
    fromPanel()->postJson('/api/v1/auth/session', ['email' => 'ana@acme.test', 'password' => 'password'])->assertOk();

    fromPanel()->postJson('/api/v1/auth/logout')->assertNoContent();

    $this->assertGuest('web');
});

it('gives the same error for a wrong password or an unknown email', function (string $email) {
    fromPanel()->postJson('/api/v1/auth/session', ['email' => $email, 'password' => 'mala'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => 'Las credenciales no son correctas.']);

    $this->assertGuest('web');
})->with(['ana@acme.test', 'nadie@acme.test']);

it('does not let users of a deactivated company in', function () {
    $user = User::factory()->for(Tenant::factory()->inactive())->create(['email' => 'luis@cerrada.test']);

    fromPanel()->postJson('/api/v1/auth/session', ['email' => 'luis@cerrada.test', 'password' => 'password'])
        ->assertForbidden()
        ->assertJson(['message' => 'La empresa está desactivada.']);

    $this->assertGuest('web');
});

it('is only for the panel: other clients must use tokens', function () {
    $this->postJson('/api/v1/auth/session', ['email' => 'ana@acme.test', 'password' => 'password'])
        ->assertStatus(400)
        ->assertJson(['message' => 'Este inicio de sesión es solo para el panel. Las integraciones usan /api/v1/auth/login.']);

    $this->assertGuest('web');
});

it('shares the login rate limit', function () {
    foreach (range(1, 5) as $_) {
        fromPanel()->postJson('/api/v1/auth/session', ['email' => 'ana@acme.test', 'password' => 'mala']);
    }

    fromPanel()->postJson('/api/v1/auth/session', ['email' => 'ana@acme.test', 'password' => 'password'])->assertTooManyRequests();
});

it('answers a missing csrf token with a clean json error', function () {
    // En los tests Laravel no comprueba el CSRF: se simula el error que lanza.
    Illuminate\Support\Facades\Route::post('/api/v1/test-csrf', fn () => throw new Illuminate\Session\TokenMismatchException('CSRF token mismatch.'));

    $this->postJson('/api/v1/test-csrf')
        ->assertStatus(419)
        ->assertExactJson(['message' => 'La sesión ha caducado. Recarga la página.']);
});
