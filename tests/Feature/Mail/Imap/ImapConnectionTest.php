<?php

use App\Mail\Credentials\ImapSmtpCredentials;
use App\Mail\Enums\MailEncryption;
use App\Mail\Exceptions\MailConnectionException;
use App\Mail\Providers\Imap\ImapClient;
use App\Mail\Security\MailHostGuard;
use Tests\Support\GreenMail;

/*
 * Contra GreenMail real (servicio "greenmail" de docker-compose / CI).
 */

function imapClient(ImapSmtpCredentials $credentials, bool $allowPrivate = true, int $timeout = 5): ImapClient
{
    return new ImapClient($credentials, new MailHostGuard($allowPrivate), $timeout);
}

function withImap(ImapSmtpCredentials $credentials, array $changes): ImapSmtpCredentials
{
    return ImapSmtpCredentials::fromArray(array_merge($credentials->toArray(), $changes));
}

it('connects to the imap server', function () {
    $email = GreenMail::createAccount();
    $client = imapClient(GreenMail::credentials($email));

    $mailbox = $client->mailbox();

    expect($mailbox->connected())->toBeTrue()
        ->and($client->mailbox())->toBe($mailbox); // reutiliza la conexión

    $client->disconnect();
    expect($mailbox->connected())->toBeFalse();
})->group('greenmail');

it('fails with a connection error when nothing listens on the port', function () {
    $credentials = withImap(GreenMail::credentials('x@greenmail.test'), ['imap_port' => 3999]);

    imapClient($credentials)->mailbox();
})->group('greenmail')->throws(MailConnectionException::class, 'No se pudo conectar con el servidor IMAP');

it('fails when tls is required but the server does not speak it', function () {
    $email = GreenMail::createAccount();
    $credentials = withImap(GreenMail::credentials($email), ['imap_encryption' => MailEncryption::Ssl->value]);

    imapClient($credentials)->mailbox();
})->group('greenmail')->throws(MailConnectionException::class);

it('respects the connection timeout', function () {
    // IP no enrutable: la conexión no responde nunca.
    $credentials = withImap(GreenMail::credentials('x@greenmail.test'), ['imap_host' => '10.255.255.1']);
    $start = microtime(true);

    expect(fn () => imapClient($credentials, timeout: 2)->mailbox())->toThrow(MailConnectionException::class)
        ->and(microtime(true) - $start)->toBeLessThan(6);
});

it('checks the host again before connecting (anti-SSRF / DNS rebinding)', function () {
    $credentials = GreenMail::credentials('x@greenmail.test');

    imapClient($credentials, allowPrivate: false)->mailbox();
})->throws(MailConnectionException::class, 'no permitida');

it('never includes the password in connection errors', function () {
    $credentials = withImap(GreenMail::credentials('x@greenmail.test', 'password-muy-secreta'), ['imap_port' => 3999]);

    try {
        imapClient($credentials)->mailbox();
    } catch (MailConnectionException $e) {
        expect($e->getMessage())->not->toContain('password-muy-secreta');
    }
})->group('greenmail');
