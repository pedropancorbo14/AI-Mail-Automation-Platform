<?php

use App\Mail\Exceptions\MailConnectionException;
use App\Mail\Security\MailHostGuard;

dataset('internal hosts', [
    'loopback' => ['127.0.0.1'],
    'localhost' => ['localhost'],
    'red privada 10/8' => ['10.0.0.5'],
    'red privada 192.168' => ['192.168.1.10'],
    'red docker 172.16/12' => ['172.18.0.3'],
    'metadatos de la nube' => ['169.254.169.254'],
    'ipv6 loopback' => ['[::1]'],
    'sin resolver' => ['no-existe.invalid'],
]);

it('rejects internal or unresolvable hosts', function (string $host) {
    expect((new MailHostGuard(false))->reject($host))->not->toBeNull();
})->with('internal hosts');

it('throws a mail connection exception when asserting an internal host', function () {
    (new MailHostGuard(false))->assertAllowed('169.254.169.254');
})->throws(MailConnectionException::class, 'interna');

it('allows public hosts', function () {
    expect((new MailHostGuard(false))->reject('8.8.8.8'))->toBeNull();
});

it('allows internal hosts only when explicitly enabled (development)', function () {
    expect((new MailHostGuard(true))->reject('127.0.0.1'))->toBeNull()
        ->and((new MailHostGuard(true))->reject('172.18.0.3'))->toBeNull();
});

// Fase 12: encontrado en la prueba de ataques contra el stack local.
it('never allows link-local or cloud metadata addresses, even in development', function (string $host) {
    expect((new MailHostGuard(true))->reject($host))->not->toBeNull()
        ->and((new MailHostGuard(true))->allowedIps($host))->toBe([])
        ->and((new MailHostGuard(false))->reject($host))->not->toBeNull();
})->with([
    'metadatos de la nube' => ['169.254.169.254'],
    'enlace local' => ['169.254.10.1'],
    'ipv4 escrita como ipv6' => ['[::ffff:169.254.169.254]'],
    'enlace local ipv6' => ['[fe80::1]'],
    'red 0.0.0.0/8' => ['0.0.0.0'],
]);

it('rejects private addresses written as ipv6 in production', function () {
    expect((new MailHostGuard(false))->reject('[::ffff:10.0.0.5]'))->not->toBeNull()
        ->and((new MailHostGuard(false))->reject('[::ffff:8.8.8.8]'))->toBeNull();
});

it('ignores the private hosts setting outside local and testing', function () {
    config(['mail_connections.allow_private_hosts' => true]);
    app()->detectEnvironment(fn () => 'production');

    try {
        expect(app(MailHostGuard::class)->reject('172.18.0.3'))->not->toBeNull();
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }

    expect(app(MailHostGuard::class)->reject('172.18.0.3'))->toBeNull();
});
