<?php

use App\Models\Tenant;
use App\Models\User;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->owner = User::factory()->for($this->tenant)->owner()->create();
    $this->admin = User::factory()->for($this->tenant)->admin()->create();
    $this->agent = User::factory()->for($this->tenant)->agent()->create();
});

function newUserData(array $overrides = []): array
{
    return array_merge([
        'name' => 'Nuevo Usuario',
        'email' => 'nuevo@acme.test',
        'password' => 'contraseña-segura',
        'role' => 'agent',
    ], $overrides);
}

// --- Listar / ver ---------------------------------------------------------

it('lists the users of the company', function () {
    $this->actingAs($this->agent, 'sanctum')
        ->getJson('/api/v1/users')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure(['data' => [['id', 'name', 'email', 'role']], 'meta', 'links']);
});

it('shows a user of the company', function () {
    $this->actingAs($this->agent, 'sanctum')
        ->getJson("/api/v1/users/{$this->admin->id}")
        ->assertOk()
        ->assertJsonPath('data.role', 'admin');
});

// --- Crear -----------------------------------------------------------------

it('lets an admin create users in its own company', function () {
    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/users', newUserData())
        ->assertCreated()
        ->assertJsonPath('data.role', 'agent');

    $created = User::where('email', 'nuevo@acme.test')->firstOrFail();
    expect($created->tenant_id)->toBe($this->tenant->id);
});

it('ignores a tenant_id sent by the client when creating', function () {
    $other = Tenant::factory()->create();

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/users', newUserData(['tenant_id' => $other->id]))
        ->assertCreated();

    expect(User::where('email', 'nuevo@acme.test')->value('tenant_id'))->toBe($this->tenant->id);
});

it('does not let an agent create users', function () {
    $this->actingAs($this->agent, 'sanctum')
        ->postJson('/api/v1/users', newUserData())
        ->assertForbidden();
});

it('only lets an owner create another owner', function () {
    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/users', newUserData(['role' => 'owner']))
        ->assertForbidden();

    $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/v1/users', newUserData(['role' => 'owner']))
        ->assertCreated();
});

it('validates the new user', function () {
    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/users', newUserData([
            'email' => $this->agent->email,
            'password' => 'corta',
            'role' => 'superadmin',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password', 'role']);
});

// --- Editar ----------------------------------------------------------------

it('lets an admin edit an agent', function () {
    $this->actingAs($this->admin, 'sanctum')
        ->patchJson("/api/v1/users/{$this->agent->id}", ['name' => 'Agente Renombrado', 'role' => 'admin'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Agente Renombrado')
        ->assertJsonPath('data.role', 'admin');
});

it('does not let an admin edit or promote to owner', function () {
    $this->actingAs($this->admin, 'sanctum')
        ->patchJson("/api/v1/users/{$this->owner->id}", ['name' => 'Hackeado'])
        ->assertForbidden();

    $this->actingAs($this->admin, 'sanctum')
        ->patchJson("/api/v1/users/{$this->agent->id}", ['role' => 'owner'])
        ->assertForbidden();
});

it('does not let anyone change their own role', function () {
    $this->actingAs($this->owner, 'sanctum')
        ->patchJson("/api/v1/users/{$this->owner->id}", ['role' => 'agent'])
        ->assertForbidden();

    expect($this->owner->fresh()->role->name)->toBe('owner');
});

it('lets users edit their own name keeping their role', function () {
    $this->actingAs($this->admin, 'sanctum')
        ->patchJson("/api/v1/users/{$this->admin->id}", ['name' => 'Yo Mismo', 'role' => 'admin'])
        ->assertOk();
});

it('does not let an agent edit users', function () {
    $this->actingAs($this->agent, 'sanctum')
        ->patchJson("/api/v1/users/{$this->admin->id}", ['name' => 'x'])
        ->assertForbidden();
});

// --- Eliminar --------------------------------------------------------------

it('lets an admin delete an agent and revokes its tokens', function () {
    $this->agent->createToken('panel');

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/v1/users/{$this->agent->id}")
        ->assertNoContent();

    $this->assertModelMissing($this->agent);
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('does not let anyone delete themselves', function () {
    $this->actingAs($this->owner, 'sanctum')
        ->deleteJson("/api/v1/users/{$this->owner->id}")
        ->assertForbidden();
});

it('does not let an admin delete an owner', function () {
    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/v1/users/{$this->owner->id}")
        ->assertForbidden();
});

it('does not let an agent delete users', function () {
    $this->actingAs($this->agent, 'sanctum')
        ->deleteJson("/api/v1/users/{$this->admin->id}")
        ->assertForbidden();
});
