<?php

use App\Mail\Exceptions\MailAuthenticationException;
use App\Mail\Exceptions\MailConnectionException;
use App\Mail\Exceptions\MailProviderException;
use App\Mail\Providers\Imap\ImapClient;
use App\Mail\Security\MailHostGuard;
use Tests\Support\GreenMail;

function greenmailImapClient(string $email, string $password): ImapClient
{
    return new ImapClient(GreenMail::credentials($email, $password), new MailHostGuard(true), 5);
}

it('logs in with valid credentials', function () {
    $email = GreenMail::createAccount('clave-correcta');

    greenmailImapClient($email, 'clave-correcta')->verify();

    expect(true)->toBeTrue();
})->group('greenmail');

it('reports a wrong password as an authentication error', function () {
    $email = GreenMail::createAccount('clave-correcta');

    expect(fn () => greenmailImapClient($email, 'clave-incorrecta')->verify())
        ->toThrow(MailAuthenticationException::class, "rechazó el usuario o la contraseña de {$email}");
})->group('greenmail');

it('reports an unknown user as an authentication error', function () {
    expect(fn () => greenmailImapClient('nadie@greenmail.test', 'x')->verify())
        ->toThrow(MailAuthenticationException::class);
})->group('greenmail');

it('distinguishes authentication errors from connection errors', function () {
    $email = GreenMail::createAccount('clave-correcta');

    try {
        greenmailImapClient($email, 'clave-incorrecta')->verify();
    } catch (MailProviderException $e) {
        expect($e)->toBeInstanceOf(MailAuthenticationException::class)
            ->not->toBeInstanceOf(MailConnectionException::class)
            ->and($e->getMessage())->not->toContain('clave-incorrecta');

        return;
    }

    $this->fail('Debería haber fallado la autenticación.');
})->group('greenmail');
