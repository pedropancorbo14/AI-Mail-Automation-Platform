<?php

use App\Mail\Data\ConnectionTestResult;
use App\Mail\Enums\MailConnectionStatus;
use App\Mail\Enums\MailProviderType;
use App\Mail\MailConnectionService;
use App\Mail\MailProviderFactory;
use App\Models\MailConnection;
use App\Models\Tenant;
use App\Models\User;
use Tests\Fakes\FakeMailProvider;
use Tests\Support\GreenMail;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->admin = User::factory()->for($this->tenant)->admin()->create();
});

function fakeImapResult(ConnectionTestResult $result): void
{
    app(MailProviderFactory::class)->extend(MailProviderType::Imap, fn () => new FakeMailProvider(testResult: $result));
}

function greenmailConnection(Tenant $tenant, string $email, string $password = 'secret'): MailConnection
{
    return inTenant($tenant, fn () => MailConnection::factory()->create([
        'email' => $email,
        'credentials' => GreenMail::credentials($email, $password),
    ]));
}

// --- Proveedor IMAP/SMTP real (GreenMail) ------------------------------------

it('tests imap and smtp against a real server', function () {
    $email = GreenMail::createAccount();

    $result = GreenMail::provider($email)->testConnection();

    expect($result->ok())->toBeTrue()
        ->and($result->error())->toBeNull();
})->group('greenmail');

it('reports which side fails without throwing', function () {
    $email = GreenMail::createAccount('buena');

    $wrongPassword = GreenMail::provider($email, 'mala')->testConnection();

    expect($wrongPassword->incomingOk)->toBeFalse()
        ->and($wrongPassword->outgoingOk)->toBeFalse()
        ->and($wrongPassword->incomingError)->toContain('IMAP rechazó')
        ->and($wrongPassword->outgoingError)->toContain('SMTP rechazó');
})->group('greenmail');

// --- Servicio: guarda el resultado ------------------------------------------

it('marks the connection as active when the test passes', function () {
    config(['mail_connections.allow_private_hosts' => true]);
    $email = GreenMail::createAccount();
    $connection = greenmailConnection($this->tenant, $email);

    $this->freezeSecond();
    $result = inTenant($this->tenant, fn () => app(MailConnectionService::class)->test($connection));

    expect($result->ok())->toBeTrue();

    $connection = inTenant($this->tenant, fn () => $connection->fresh());
    expect($connection->status)->toBe(MailConnectionStatus::Active)
        ->and($connection->last_tested_at->equalTo(now()))->toBeTrue()
        ->and($connection->last_error)->toBeNull();
})->group('greenmail');

it('marks the connection as failing and stores the error', function () {
    fakeImapResult(new ConnectionTestResult(true, false, outgoingError: 'SMTP caído'));
    $connection = inTenant($this->tenant, fn () => MailConnection::factory()->create());

    inTenant($this->tenant, fn () => app(MailConnectionService::class)->test($connection));

    expect($connection->status)->toBe(MailConnectionStatus::Failing)
        ->and($connection->last_error)->toBe('SMTP caído');
});

it('keeps disabled connections disabled', function () {
    fakeImapResult(new ConnectionTestResult(true, true));
    $connection = inTenant($this->tenant, fn () => MailConnection::factory()->create());
    inTenant($this->tenant, fn () => $connection->forceFill(['status' => MailConnectionStatus::Disabled])->save());

    inTenant($this->tenant, fn () => app(MailConnectionService::class)->test($connection));

    expect($connection->status)->toBe(MailConnectionStatus::Disabled)
        ->and($connection->last_tested_at)->not->toBeNull();
});

it('reports providers that are not available yet as a failed test', function () {
    $connection = inTenant($this->tenant, fn () => MailConnection::factory()->google()->create());
    (fn () => $this->resolvers = [])->call(app(MailProviderFactory::class)); // sin proveedores registrados

    $result = inTenant($this->tenant, fn () => app(MailConnectionService::class)->test($connection));

    expect($result->ok())->toBeFalse()
        ->and($connection->status)->toBe(MailConnectionStatus::Failing)
        ->and($connection->last_error)->toContain('google');
});

// --- Endpoint ----------------------------------------------------------------

it('tests a connection through the api', function () {
    fakeImapResult(new ConnectionTestResult(false, true, incomingError: 'El servidor IMAP rechazó el usuario'));
    $connection = inTenant($this->tenant, fn () => MailConnection::factory()->create());

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/mail-connections/{$connection->id}/test")
        ->assertOk()
        ->assertJsonPath('result.ok', false)
        ->assertJsonPath('result.incoming_ok', false)
        ->assertJsonPath('result.outgoing_ok', true)
        ->assertJsonPath('result.incoming_error', 'El servidor IMAP rechazó el usuario')
        ->assertJsonPath('data.status', 'failing')
        ->assertJsonPath('data.last_error', 'El servidor IMAP rechazó el usuario');
});

it('tests a real imap/smtp connection end to end through the api', function () {
    config(['mail_connections.allow_private_hosts' => true]);
    $connection = greenmailConnection($this->tenant, GreenMail::createAccount());

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/mail-connections/{$connection->id}/test")
        ->assertOk()
        ->assertJsonPath('result.ok', true)
        ->assertJsonPath('data.status', 'active');
})->group('greenmail');

it('does not let agents test connections', function () {
    $connection = inTenant($this->tenant, fn () => MailConnection::factory()->create());

    $this->actingAs(User::factory()->for($this->tenant)->agent()->create(), 'sanctum')
        ->postJson("/api/v1/mail-connections/{$connection->id}/test")
        ->assertForbidden();
});

it('cannot test connections of another company', function () {
    $foreign = inTenant(Tenant::factory()->create(), fn () => MailConnection::factory()->create());

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/mail-connections/{$foreign->id}/test")
        ->assertNotFound();
});

it('limits how often connections can be tested', function () {
    fakeImapResult(new ConnectionTestResult(true, true));
    $connection = inTenant($this->tenant, fn () => MailConnection::factory()->create());

    foreach (range(1, 10) as $i) {
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/mail-connections/{$connection->id}/test")->assertOk();
    }

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/mail-connections/{$connection->id}/test")
        ->assertStatus(429);
});
