<?php

use App\Models\Tenant;
use App\Models\User;
use App\Security\Audit\AuditLogger;

beforeEach(function () {
    $this->acme = Tenant::factory()->create();
    $this->owner = User::factory()->for($this->acme)->owner()->create(['name' => 'Olga', 'email' => 'olga@acme.test']);
    $this->admin = User::factory()->for($this->acme)->admin()->create(['name' => 'Ana']);
    $this->agent = User::factory()->for($this->acme)->agent()->create(['name' => 'Lucía']);
    $this->logger = app(AuditLogger::class);
});

function auditRecord(string $action, User $actor, array $extra = []): void
{
    app(AuditLogger::class)->record($action, $extra['subject'] ?? null, $extra['label'] ?? null, $extra['changes'] ?? [], $extra['metadata'] ?? [], $actor);
}

it('lists the audit log of the company, newest first, with actor, subject and changes', function () {
    auditRecord('tenant.updated', $this->owner, ['subject' => $this->acme, 'label' => 'Acme SL', 'changes' => ['name' => ['from' => 'Acme', 'to' => 'Acme SL']]]);
    auditRecord('automation.tool_changed', $this->admin, ['label' => 'get_order', 'metadata' => ['tool' => 'get_order', 'enabled' => true]]);

    $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/audit-logs')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.action', 'automation.tool_changed')
        ->assertJsonPath('data.0.actor.name', 'Ana')
        ->assertJsonPath('data.0.subject', ['type' => null, 'id' => null, 'label' => 'get_order'])
        ->assertJsonPath('data.0.metadata.enabled', true)
        ->assertJsonPath('data.1.action', 'tenant.updated')
        ->assertJsonPath('data.1.actor', ['id' => $this->owner->id, 'name' => 'Olga', 'email' => 'olga@acme.test'])
        ->assertJsonPath('data.1.subject.type', 'Tenant')
        ->assertJsonPath('data.1.changes.name.to', 'Acme SL')
        ->assertJsonStructure(['data' => [['id', 'action', 'actor', 'subject', 'changes', 'metadata', 'ip', 'user_agent', 'request_id', 'created_at']], 'meta']);
});

it('keeps the email of a user that no longer exists', function () {
    $gone = User::factory()->for($this->acme)->admin()->create(['email' => 'ex@acme.test']);
    auditRecord('document.deleted', $gone);
    $gone->delete();

    $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/audit-logs')
        ->assertJsonPath('data.0.actor', ['id' => $gone->id, 'name' => null, 'email' => 'ex@acme.test']);
});

it('filters by category, person and dates', function () {
    auditRecord('user.created', $this->owner);
    auditRecord('user.deleted', $this->admin);
    auditRecord('document.uploaded', $this->admin);
    $this->travel(-10)->days();
    auditRecord('user.updated', $this->owner);
    $this->travelBack();

    $get = fn (array $query) => array_column(
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/audit-logs?' . http_build_query($query))->assertOk()->json('data'),
        'action',
    );

    expect($get(['category' => 'user']))->toBe(['user.deleted', 'user.created', 'user.updated'])
        ->and($get(['user' => $this->admin->id]))->toBe(['document.uploaded', 'user.deleted'])
        ->and($get(['category' => 'user', 'user' => $this->owner->id, 'from' => now()->subDay()->toDateString()]))->toBe(['user.created'])
        ->and($get(['to' => now()->subDays(5)->toDateString()]))->toBe(['user.updated']);
});

it('rejects invalid filters', function () {
    $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/audit-logs?category=users%25&from=2026-10-10&to=2026-10-01')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['category', 'to']);
});

it('paginates', function () {
    foreach (range(1, 3) as $i) {
        auditRecord('user.created', $this->owner);
    }

    $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/audit-logs?per_page=2')
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 3);
});

it('is only for owners and admins', function () {
    $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/audit-logs')->assertOk();
    app('auth')->forgetGuards();
    $this->actingAs($this->agent, 'sanctum')->getJson('/api/v1/audit-logs')->assertForbidden();
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/audit-logs')->assertUnauthorized();
});

it('never shows the log of another company nor failed logins without a company', function () {
    $globex = Tenant::factory()->create();
    $globexOwner = User::factory()->for($globex)->owner()->create();
    auditRecord('tenant.updated', $globexOwner);
    $this->logger->record('auth.login_failed', metadata: ['email' => 'nadie@x.test']);
    auditRecord('user.created', $this->owner);

    $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/audit-logs')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.action', 'user.created');

    // Ni filtrando por el usuario de la otra empresa.
    $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/audit-logs?user=' . $globexOwner->id)
        ->assertJsonCount(0, 'data');
});

it('has no route to change or delete the log', function (string $method) {
    auditRecord('user.created', $this->owner);

    $this->actingAs($this->owner, 'sanctum')->json($method, '/api/v1/audit-logs/1')->assertNotFound();
})->with(['GET', 'PATCH', 'PUT', 'DELETE']);
