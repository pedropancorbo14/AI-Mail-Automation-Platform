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
    expect((new MailHostGuard(true))->reject('127.0.0.1'))->toBeNull();
});
