<?php

use App\Mail\Credentials\ImapSmtpCredentials;
use App\Mail\Enums\MailConnectionStatus;
use App\Models\MailConnection;
use App\Models\Tenant;
use App\Models\User;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->admin = User::factory()->for($this->tenant)->admin()->create();
    $this->agent = User::factory()->for($this->tenant)->agent()->create();
});

/** IPs públicas literales: pasan el control anti-SSRF sin depender del DNS. */
function imapPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'name' => 'Soporte',
        'email' => 'soporte@acme.test',
        'provider' => 'imap',
        'imap' => ['host' => '8.8.8.8', 'port' => 993, 'encryption' => 'ssl', 'username' => 'soporte@acme.test', 'password' => 'secreto-imap'],
        'smtp' => ['host' => '1.1.1.1', 'port' => 587, 'encryption' => 'starttls', 'username' => 'soporte@acme.test', 'password' => 'secreto-smtp'],
    ], $overrides);
}

function connectionOf(Tenant $tenant, array $attributes = []): MailConnection
{
    return inTenant($tenant, fn () => MailConnection::factory()->create($attributes));
}

// --- Crear -------------------------------------------------------------------

it('creates an imap connection in the company of the user', function () {
    $response = $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/mail-connections', imapPayload())
        ->assertCreated()
        ->assertJsonPath('data.provider', 'imap')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.settings.imap.host', '8.8.8.8')
        ->assertJsonPath('data.settings.smtp.encryption', 'starttls')
        ->assertJsonPath('data.created_by', $this->admin->id);

    expect($response->content())->not->toContain('secreto');

    $connection = inTenant($this->tenant, fn () => MailConnection::findOrFail($response->json('data.id')));
    expect($connection->tenant_id)->toBe($this->tenant->id)
        ->and($connection->credentials->imapPassword)->toBe('secreto-imap');
});

it('does not allow agents to create connections', function () {
    $this->actingAs($this->agent, 'sanctum')
        ->postJson('/api/v1/mail-connections', imapPayload())
        ->assertForbidden();
});

it('validates the connection data', function () {
    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/mail-connections', imapPayload([
            'email' => 'no-es-email',
            'imap' => ['port' => 99999, 'encryption' => 'magia'],
            'smtp' => ['password' => ''],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'imap.port', 'imap.encryption', 'smtp.password']);
});

it('only creates imap connections through this endpoint', function () {
    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/mail-connections', imapPayload(['provider' => 'google']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['provider']);
});

it('rejects hosts pointing to the internal network (SSRF)', function (string $host) {
    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/mail-connections', imapPayload(['imap' => ['host' => $host], 'smtp' => ['host' => $host]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['imap.host', 'smtp.host']);
})->with(['redis', 'postgres', '127.0.0.1', '169.254.169.254', '10.0.0.1']);

it('allows internal hosts when enabled for local development', function () {
    config(['mail_connections.allow_private_hosts' => true]);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/mail-connections', imapPayload(['imap' => ['host' => 'greenmail'], 'smtp' => ['host' => 'greenmail']]))
        ->assertCreated();
});

it('does not allow the same account twice in the company', function () {
    connectionOf($this->tenant, ['email' => 'soporte@acme.test']);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/mail-connections', imapPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

// --- Listar / ver ------------------------------------------------------------

it('lists the connections of the company without secrets', function () {
    connectionOf($this->tenant);
    connectionOf($this->tenant);

    $response = $this->actingAs($this->agent, 'sanctum')
        ->getJson('/api/v1/mail-connections')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect($response->content())->not->toContain('password')
        ->not->toContain('credentials');
});

it('shows a connection', function () {
    $connection = connectionOf($this->tenant);

    $this->actingAs($this->agent, 'sanctum')
        ->getJson("/api/v1/mail-connections/{$connection->id}")
        ->assertOk()
        ->assertJsonPath('data.email', $connection->email);
});

// --- Editar ------------------------------------------------------------------

it('updates the name without touching credentials or status', function () {
    $connection = connectionOf($this->tenant);
    inTenant($this->tenant, fn () => $connection->forceFill(['status' => MailConnectionStatus::Active])->save());

    $this->actingAs($this->admin, 'sanctum')
        ->patchJson("/api/v1/mail-connections/{$connection->id}", ['name' => 'Ventas'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Ventas')
        ->assertJsonPath('data.status', 'active');
});

it('keeps the current password when it is not sent and resets the status', function () {
    $connection = connectionOf($this->tenant);
    inTenant($this->tenant, fn () => $connection->forceFill(['status' => MailConnectionStatus::Active])->save());

    $this->actingAs($this->admin, 'sanctum')
        ->patchJson("/api/v1/mail-connections/{$connection->id}", ['imap' => ['host' => '9.9.9.9', 'port' => 143, 'encryption' => 'starttls']])
        ->assertOk()
        ->assertJsonPath('data.settings.imap.host', '9.9.9.9')
        ->assertJsonPath('data.status', 'pending');

    /** @var ImapSmtpCredentials $credentials */
    $credentials = inTenant($this->tenant, fn () => $connection->fresh()->credentials);
    expect($credentials->imapPassword)->toBe('password')
        ->and($credentials->imapPort)->toBe(143);
});

it('can disable and re-enable a connection', function () {
    $connection = connectionOf($this->tenant);

    $this->actingAs($this->admin, 'sanctum')
        ->patchJson("/api/v1/mail-connections/{$connection->id}", ['enabled' => false])
        ->assertJsonPath('data.status', 'disabled');

    $this->actingAs($this->admin, 'sanctum')
        ->patchJson("/api/v1/mail-connections/{$connection->id}", ['enabled' => true])
        ->assertJsonPath('data.status', 'pending');
});

it('does not allow agents to update or delete', function () {
    $connection = connectionOf($this->tenant);

    $this->actingAs($this->agent, 'sanctum')
        ->patchJson("/api/v1/mail-connections/{$connection->id}", ['name' => 'x'])
        ->assertForbidden();

    $this->actingAs($this->agent, 'sanctum')
        ->deleteJson("/api/v1/mail-connections/{$connection->id}")
        ->assertForbidden();
});

it('deletes a connection', function () {
    $connection = connectionOf($this->tenant);

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/v1/mail-connections/{$connection->id}")
        ->assertNoContent();

    expect(inTenant($this->tenant, fn () => MailConnection::count()))->toBe(0);
});

// --- Aislamiento -------------------------------------------------------------

it('cannot see, edit or delete connections of another company', function () {
    $foreign = connectionOf(Tenant::factory()->create());
    connectionOf($this->tenant);

    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/mail-connections')
        ->assertJsonCount(1, 'data')
        ->assertJsonMissing(['id' => $foreign->id]);

    $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/mail-connections/{$foreign->id}")->assertNotFound();
    $this->actingAs($this->admin, 'sanctum')->patchJson("/api/v1/mail-connections/{$foreign->id}", ['name' => 'x'])->assertNotFound();
    $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/v1/mail-connections/{$foreign->id}")->assertNotFound();

    expect(inTenant($foreign->tenant, fn () => MailConnection::find($foreign->id)))->not->toBeNull();
});

it('does not delete a connection that has stored emails', function () {
    $connection = connectionOf($this->tenant);
    inTenant($this->tenant, fn () => \App\Models\Email::factory()->for($connection)->create());

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/v1/mail-connections/{$connection->id}")
        ->assertStatus(409)
        ->assertJsonPath('message', 'La conexión tiene correos guardados: desactívala en lugar de borrarla.');

    expect(inTenant($this->tenant, fn () => MailConnection::count()))->toBe(1);
});
