<?php

use App\Mail\Credentials\ImapSmtpCredentials;
use App\Mail\Credentials\OAuthCredentials;
use App\Mail\Enums\MailConnectionStatus;
use App\Mail\Enums\MailProviderType;
use App\Models\MailConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Exceptions\MissingTenantException;

beforeEach(function () {
    [$this->acme, $this->globex] = Tenant::factory()->count(2)->create();
});

it('belongs to the tenant in context', function () {
    $connection = inTenant($this->acme, fn () => MailConnection::factory()->create());

    expect($connection->tenant_id)->toBe($this->acme->id)
        ->and($connection->tenant->is($this->acme))->toBeTrue();
});

it('casts provider, status and credentials', function () {
    $connection = inTenant($this->acme, fn () => MailConnection::factory()->create()->fresh());

    expect($connection->provider)->toBe(MailProviderType::Imap)
        ->and($connection->status)->toBe(MailConnectionStatus::Pending)
        ->and($connection->credentials)->toBeInstanceOf(ImapSmtpCredentials::class)
        ->and($connection->credentials->imapPassword)->toBe('password');
});

it('supports oauth providers', function () {
    $connection = inTenant($this->acme, fn () => MailConnection::factory()->microsoft()->create()->fresh());

    expect($connection->provider)->toBe(MailProviderType::Microsoft)
        ->and($connection->provider->usesOAuth())->toBeTrue()
        ->and($connection->credentials)->toBeInstanceOf(OAuthCredentials::class);
});

it('never includes credentials when serialized', function () {
    $connection = inTenant($this->acme, fn () => MailConnection::factory()->create());

    expect($connection->toArray())->not->toHaveKey('credentials')
        ->and($connection->toJson())->not->toContain('password');
});

it('rejects credentials that do not match the provider', function () {
    inTenant($this->acme, fn () => MailConnection::factory()->create([
        'provider' => MailProviderType::Google,
    ]));
})->throws(InvalidArgumentException::class, 'OAuthCredentials');

it('only sees connections of the tenant in context', function () {
    inTenant($this->acme, fn () => MailConnection::factory()->count(2)->create());
    $foreign = inTenant($this->globex, fn () => MailConnection::factory()->create());

    inTenant($this->acme, function () use ($foreign) {
        expect(MailConnection::count())->toBe(2)
            ->and(MailConnection::find($foreign->id))->toBeNull()
            ->and($this->acme->mailConnections()->count())->toBe(2);
    });
});

it('refuses to query or create connections without a tenant in context', function () {
    expect(fn () => MailConnection::count())->toThrow(MissingTenantException::class)
        ->and(fn () => MailConnection::factory()->create())->toThrow(MissingTenantException::class);
});

it('records who created the connection', function () {
    $user = User::factory()->for($this->acme)->create();

    $connection = inTenant($this->acme, fn () => MailConnection::factory()->create(['created_by' => $user->id]));

    expect($connection->creator->is($user))->toBeTrue();
});
