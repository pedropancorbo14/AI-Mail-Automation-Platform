<?php

use App\Mail\Credentials\ImapSmtpCredentials;
use App\Mail\Enums\MailEncryption;
use App\Mail\Exceptions\MailAuthenticationException;
use App\Mail\Exceptions\MailConnectionException;
use App\Mail\Providers\Imap\SmtpClient;
use App\Mail\Security\MailHostGuard;
use Tests\Support\GreenMail;

function smtpClient(ImapSmtpCredentials $credentials, bool $allowPrivate = true, int $timeout = 5): SmtpClient
{
    return new SmtpClient($credentials, new MailHostGuard($allowPrivate), $timeout);
}

function withSmtp(ImapSmtpCredentials $credentials, array $changes): ImapSmtpCredentials
{
    return ImapSmtpCredentials::fromArray(array_merge($credentials->toArray(), $changes));
}

it('connects and authenticates against the smtp server', function () {
    $email = GreenMail::createAccount();
    $client = smtpClient(GreenMail::credentials($email));

    $transport = $client->transport();

    expect($client->transport())->toBe($transport); // reutiliza la conexión

    $client->disconnect();
})->group('greenmail');

it('reports a wrong smtp password as an authentication error', function () {
    $email = GreenMail::createAccount('clave-correcta');

    try {
        smtpClient(GreenMail::credentials($email, 'clave-incorrecta'))->verify();
    } catch (MailAuthenticationException $e) {
        expect($e)->not->toBeInstanceOf(MailConnectionException::class)
            ->and($e->getMessage())->toContain("rechazó el usuario o la contraseña de {$email}")
            ->not->toContain('clave-incorrecta');

        return;
    }

    $this->fail('Debería haber fallado la autenticación SMTP.');
})->group('greenmail');

it('reports an unknown smtp user as an authentication error', function () {
    smtpClient(GreenMail::credentials('nadie@greenmail.test', 'x'))->verify();
})->group('greenmail')->throws(MailAuthenticationException::class);

it('fails with a connection error when nothing listens on the port', function () {
    $credentials = withSmtp(GreenMail::credentials('x@greenmail.test'), ['smtp_port' => 3999]);

    smtpClient($credentials)->verify();
})->group('greenmail')->throws(MailConnectionException::class, 'No se pudo conectar con el servidor SMTP');

it('refuses to continue without tls when starttls is required', function () {
    // GreenMail (puerto de pruebas) no ofrece STARTTLS: no se debe enviar nada en claro.
    $email = GreenMail::createAccount();
    $credentials = withSmtp(GreenMail::credentials($email), ['smtp_encryption' => MailEncryption::StartTls->value]);

    smtpClient($credentials)->verify();
})->group('greenmail')->throws(MailConnectionException::class);

it('respects the connection timeout', function () {
    $credentials = withSmtp(GreenMail::credentials('x@greenmail.test'), ['smtp_host' => '10.255.255.1']);
    $start = microtime(true);

    expect(fn () => smtpClient($credentials, timeout: 2)->verify())->toThrow(MailConnectionException::class)
        ->and(microtime(true) - $start)->toBeLessThan(6);
});

it('checks the host again before connecting (anti-SSRF / DNS rebinding)', function () {
    smtpClient(GreenMail::credentials('x@greenmail.test'), allowPrivate: false)->verify();
})->throws(MailConnectionException::class, 'no permitida');

it('never includes the password in connection errors', function () {
    $credentials = withSmtp(GreenMail::credentials('x@greenmail.test', 'password-muy-secreta'), ['smtp_port' => 3999]);

    try {
        smtpClient($credentials)->verify();
    } catch (MailConnectionException $e) {
        expect($e->getMessage())->not->toContain('password-muy-secreta');
    }
})->group('greenmail');
