<?php

use App\Models\Tenant;
use App\Models\User;

/*
 * Dos empresas completas. El atacante es el OWNER de Acme (tiene todos los
 * permisos): aun así no debe poder ver, modificar ni borrar nada de Globex.
 * Se usan tokens reales obtenidos con login, como haría un cliente externo.
 */
beforeEach(function () {
    $this->acme = Tenant::factory()->create(['name' => 'Acme']);
    $this->globex = Tenant::factory()->create(['name' => 'Globex']);

    $this->acmeOwner = User::factory()->for($this->acme)->owner()->create(['email' => 'owner@acme.test']);
    $this->acmeAgent = User::factory()->for($this->acme)->agent()->create();

    $this->globexOwner = User::factory()->for($this->globex)->owner()->create(['email' => 'owner@globex.test']);
    $this->globexAgent = User::factory()->for($this->globex)->agent()->create(['email' => 'agent@globex.test']);

    $this->acmeToken = $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@acme.test',
        'password' => 'password',
    ])->json('token');
});

function asAcme(): Tests\TestCase
{
    // El guard cachea el usuario entre peticiones del mismo test.
    app('auth')->forgetGuards();

    return test()->withToken(test()->acmeToken);
}

// --- Lectura -----------------------------------------------------------------

it('only lists users of its own company', function () {
    $response = asAcme()->getJson('/api/v1/users')->assertOk();

    expect($response->json('data.*.email'))
        ->toHaveCount(2)
        ->not->toContain('owner@globex.test', 'agent@globex.test')
        ->and($response->json('meta.total'))->toBe(2);
});

it('cannot read a user of another company', function () {
    asAcme()->getJson("/api/v1/users/{$this->globexAgent->id}")
        ->assertNotFound()
        ->assertExactJson(['message' => 'Recurso no encontrado.']);
});

it('gets the same 404 for another company user and for a non existing id', function () {
    $foreign = asAcme()->getJson("/api/v1/users/{$this->globexAgent->id}");
    $missing = asAcme()->getJson('/api/v1/users/999999');

    expect($foreign->status())->toBe($missing->status())
        ->and($foreign->json())->toBe($missing->json());
});

it('cannot discover other company users by enumerating ids', function () {
    foreach (User::where('tenant_id', $this->globex->id)->pluck('id') as $id) {
        asAcme()->getJson("/api/v1/users/{$id}")->assertNotFound();
    }
});

it('only sees its own company in /tenant and /auth/me', function () {
    asAcme()->getJson("/api/v1/tenant?tenant_id={$this->globex->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $this->acme->id);

    asAcme()->withHeader('X-Tenant', (string) $this->globex->id)
        ->getJson('/api/v1/auth/me')
        ->assertJsonPath('data.tenant.id', $this->acme->id);
});

// --- Escritura ---------------------------------------------------------------

it('cannot update a user of another company', function () {
    asAcme()->patchJson("/api/v1/users/{$this->globexAgent->id}", ['name' => 'Hackeado'])
        ->assertNotFound();

    expect($this->globexAgent->fresh()->name)->not->toBe('Hackeado');
});

it('cannot take over another company account by changing its email or password', function () {
    asAcme()->patchJson("/api/v1/users/{$this->globexOwner->id}", [
        'email' => 'atacante@acme.test',
        'password' => 'nueva-contraseña',
    ])->assertNotFound();

    $this->postJson('/api/v1/auth/login', ['email' => 'owner@globex.test', 'password' => 'password'])
        ->assertOk();
});

it('gets 404 and not 422 when sending invalid data to another company user', function () {
    asAcme()->patchJson("/api/v1/users/{$this->globexAgent->id}", ['email' => 'no-es-un-email'])
        ->assertNotFound();
});

it('cannot delete a user of another company', function () {
    asAcme()->deleteJson("/api/v1/users/{$this->globexAgent->id}")->assertNotFound();

    $this->assertModelExists($this->globexAgent);
});

it('cannot create users inside another company', function () {
    asAcme()->postJson('/api/v1/users', [
        'name' => 'Infiltrado',
        'email' => 'infiltrado@test.test',
        'password' => 'contraseña-segura',
        'role' => 'owner',
        'tenant_id' => $this->globex->id,
    ])->assertCreated();

    expect(User::where('email', 'infiltrado@test.test')->value('tenant_id'))->toBe($this->acme->id)
        ->and($this->globex->users()->count())->toBe(2);
});

it('cannot rename another company', function () {
    asAcme()->patchJson('/api/v1/tenant', ['name' => 'Renombrada', 'id' => $this->globex->id])
        ->assertOk();

    expect($this->globex->fresh()->name)->toBe('Globex')
        ->and($this->acme->fresh()->name)->toBe('Renombrada');
});

// --- Sesiones / estado -------------------------------------------------------

it('keeps companies independent when one is deactivated', function () {
    $globexToken = $this->globexOwner->createToken('panel')->plainTextToken;

    $this->acme->update(['is_active' => false]);

    asAcme()->getJson('/api/v1/auth/me')->assertForbidden();

    $this->app['auth']->forgetGuards();
    $this->withToken($globexToken)->getJson('/api/v1/auth/me')->assertOk();
});

it('does not revoke tokens of another company on logout', function () {
    $globexToken = $this->globexOwner->createToken('panel')->plainTextToken;

    asAcme()->postJson('/api/v1/auth/logout')->assertNoContent();

    expect($this->globexOwner->tokens()->count())->toBe(1);

    $this->app['auth']->forgetGuards();
    $this->withToken($globexToken)->getJson('/api/v1/auth/me')->assertOk();
});
