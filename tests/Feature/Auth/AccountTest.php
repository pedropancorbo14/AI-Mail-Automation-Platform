<?php

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/*
 * Cuenta propia: cambiar la contraseña y cerrar la sesión en los demás
 * dispositivos (tokens de API y sesiones del panel).
 */

beforeEach(function () {
    config(['sanctum.stateful' => ['panel.test']]);
    RateLimiter::clear('olga@acme.test|127.0.0.1');
    $this->tenant = Tenant::factory()->create();
    $this->user = User::factory()->for($this->tenant)->agent()->create(['email' => 'olga@acme.test']);
});

function tokenFor(User $user, string $device): string
{
    return $user->createToken($device)->plainTextToken;
}

function withAccountToken(string $token): Tests\TestCase
{
    app('auth')->forgetGuards();

    return test()->withToken($token);
}

function fromAccountPanel(): Tests\TestCase
{
    app('auth')->forgetGuards();

    return test()->withHeaders(['Origin' => 'http://panel.test', 'Referer' => 'http://panel.test/']);
}

it('changes the own password, keeps this token and revokes the others', function () {
    $mine = tokenFor($this->user, 'portatil');
    $other = tokenFor($this->user, 'movil');

    withAccountToken($mine)->putJson('/api/v1/auth/password', [
        'current_password' => 'password',
        'password' => 'una-clave-nueva-larga',
        'password_confirmation' => 'una-clave-nueva-larga',
    ])->assertOk();

    expect(Hash::check('una-clave-nueva-larga', $this->user->fresh()->password))->toBeTrue();
    withAccountToken($mine)->getJson('/api/v1/auth/me')->assertOk();
    withAccountToken($other)->getJson('/api/v1/auth/me')->assertUnauthorized();

    $log = inTenant($this->tenant, fn () => AuditLog::sole());
    expect($log->action)->toBe('auth.password_changed')
        ->and($log->metadata)->toEqual(['revoked_count' => 1])
        ->and(json_encode($log->getAttributes()))->not->toContain('una-clave-nueva');
});

it('requires the current password', function () {
    $this->actingAs($this->user, 'sanctum')->putJson('/api/v1/auth/password', [
        'current_password' => 'no-es-esta',
        'password' => 'una-clave-nueva-larga',
        'password_confirmation' => 'una-clave-nueva-larga',
    ])->assertUnprocessable()->assertJsonValidationErrors(['current_password']);

    expect(Hash::check('password', $this->user->fresh()->password))->toBeTrue();
});

it('validates the new password', function (array $body, string $field) {
    $this->actingAs($this->user, 'sanctum')
        ->putJson('/api/v1/auth/password', ['current_password' => 'password', ...$body])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'too short' => [['password' => 'corta-123', 'password_confirmation' => 'corta-123'], 'password'],
    'not confirmed' => [['password' => 'una-clave-nueva-larga', 'password_confirmation' => 'otra-distinta-larga'], 'password'],
    'same as now' => [['current_password' => 'password-actual-larga', 'password' => 'password-actual-larga', 'password_confirmation' => 'password-actual-larga'], 'password'],
]);

it('limits the attempts to guess the current password', function () {
    foreach (range(1, 6) as $i) {
        $this->actingAs($this->user, 'sanctum')->putJson('/api/v1/auth/password', ['current_password' => "x{$i}", 'password' => 'una-clave-nueva-larga', 'password_confirmation' => 'una-clave-nueva-larga'])
            ->assertUnprocessable();
    }

    $this->actingAs($this->user, 'sanctum')->putJson('/api/v1/auth/password', ['current_password' => 'password', 'password' => 'una-clave-nueva-larga', 'password_confirmation' => 'una-clave-nueva-larga'])
        ->assertTooManyRequests();
});

it('keeps the panel session that changed the password', function () {
    fromAccountPanel()->postJson('/api/v1/auth/session', ['email' => 'olga@acme.test', 'password' => 'password'])->assertOk();

    fromAccountPanel()->putJson('/api/v1/auth/password', [
        'current_password' => 'password',
        'password' => 'una-clave-nueva-larga',
        'password_confirmation' => 'una-clave-nueva-larga',
    ])->assertOk();

    fromAccountPanel()->getJson('/api/v1/auth/me')->assertOk();
});

it('closes a panel session when the password changes elsewhere', function () {
    fromAccountPanel()->postJson('/api/v1/auth/session', ['email' => 'olga@acme.test', 'password' => 'password'])->assertOk();
    fromAccountPanel()->getJson('/api/v1/auth/me')->assertOk();

    // Otro dispositivo (o un administrador) cambia la contraseña.
    $this->user->forceFill(['password' => 'cambiada-en-otro-sitio'])->save();

    fromAccountPanel()->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('logs out the other devices with the password, keeping this one', function () {
    $mine = tokenFor($this->user, 'portatil');
    $other = tokenFor($this->user, 'movil');
    $hash = $this->user->password;

    withAccountToken($mine)->postJson('/api/v1/auth/logout-other-devices', ['password' => 'mala'])
        ->assertUnprocessable()->assertJsonValidationErrors(['password']);
    withAccountToken($other)->getJson('/api/v1/auth/me')->assertOk();

    withAccountToken($mine)->postJson('/api/v1/auth/logout-other-devices', ['password' => 'password'])->assertOk();

    withAccountToken($mine)->getJson('/api/v1/auth/me')->assertOk();
    withAccountToken($other)->getJson('/api/v1/auth/me')->assertUnauthorized();
    // Misma contraseña, otro hash: las demás sesiones del panel dejan de valer.
    expect($this->user->fresh()->password)->not->toBe($hash)
        ->and(Hash::check('password', $this->user->fresh()->password))->toBeTrue()
        ->and(inTenant($this->tenant, fn () => AuditLog::sole()->action))->toBe('auth.logout_other_devices');
});

it('revokes the tokens of a user whose password an admin changed', function () {
    $admin = User::factory()->for($this->tenant)->admin()->create();
    $token = tokenFor($this->user, 'movil');
    $adminToken = tokenFor($admin, 'panel');

    withAccountToken($adminToken)->patchJson("/api/v1/users/{$this->user->id}", ['password' => 'reseteada-por-admin'])->assertOk();

    withAccountToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    withAccountToken($adminToken)->getJson('/api/v1/auth/me')->assertOk();
});

it('keeps the own token when changing the own password through the users api', function () {
    $owner = User::factory()->for($this->tenant)->owner()->create();
    $mine = tokenFor($owner, 'panel');
    $other = tokenFor($owner, 'movil');

    withAccountToken($mine)->patchJson("/api/v1/users/{$owner->id}", ['password' => 'una-clave-nueva-larga'])->assertOk();

    withAccountToken($mine)->getJson('/api/v1/auth/me')->assertOk();
    withAccountToken($other)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('requires being logged in', function () {
    $this->putJson('/api/v1/auth/password', [])->assertUnauthorized();
    $this->postJson('/api/v1/auth/logout-other-devices', [])->assertUnauthorized();
});
