<?php

use App\Mail\Enums\MailProviderType;
use App\Mail\Exceptions\MailProviderException;
use App\Mail\Exceptions\UnsupportedMailProviderException;
use App\Mail\MailProviderFactory;
use App\Models\MailConnection;
use App\Models\Tenant;
use Tests\Fakes\FakeMailProvider;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->factory = app(MailProviderFactory::class);
});

it('is a singleton', function () {
    expect(app(MailProviderFactory::class))->toBe($this->factory);
});

it('builds the provider registered for the connection type', function () {
    $fake = new FakeMailProvider();
    $this->factory->extend(MailProviderType::Google, fn () => $fake);

    $connection = inTenant($this->tenant, fn () => MailConnection::factory()->google()->create());

    expect($this->factory->make($connection))->toBe($fake)
        ->and($this->factory->supports(MailProviderType::Google))->toBeTrue();
});

it('passes the connection to the resolver', function () {
    $received = null;
    $this->factory->extend(MailProviderType::Google, function (MailConnection $connection) use (&$received) {
        $received = $connection;

        return new FakeMailProvider();
    });

    $connection = inTenant($this->tenant, fn () => MailConnection::factory()->google()->create());
    $this->factory->make($connection);

    expect($received->is($connection))->toBeTrue();
});

it('fails with a mail provider exception for unsupported types', function () {
    $factory = new MailProviderFactory();
    $connection = inTenant($this->tenant, fn () => MailConnection::factory()->google()->create());

    expect(fn () => $factory->make($connection))
        ->toThrow(UnsupportedMailProviderException::class, 'google')
        ->and(new UnsupportedMailProviderException())->toBeInstanceOf(MailProviderException::class);
});
