<?php

namespace App\Mail\Providers\Imap;

use App\Mail\Credentials\ImapSmtpCredentials;
use App\Mail\Enums\MailEncryption;
use App\Mail\Exceptions\MailAuthenticationException;
use App\Mail\Exceptions\MailConnectionException;
use App\Mail\Security\MailHostGuard;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Throwable;

/**
 * Conexión SMTP de una cuenta con Symfony Mailer (el mismo que usa
 * Laravel), creando un transporte por conexión en vez del mailer global.
 * Traduce los errores a nuestras excepciones.
 */
class SmtpClient
{
    private ?EsmtpTransport $transport = null;

    public function __construct(
        private readonly ImapSmtpCredentials $credentials,
        private readonly MailHostGuard $hostGuard,
        private readonly int $timeout = 15,
    ) {
    }

    /**
     * Transporte conectado y autenticado (EHLO + TLS/STARTTLS + AUTH).
     *
     * @throws MailConnectionException
     * @throws MailAuthenticationException
     */
    public function transport(): EsmtpTransport
    {
        if ($this->transport !== null) {
            return $this->transport;
        }

        // Anti-SSRF: se repite aquí porque el DNS puede haber cambiado desde que se validó.
        $this->hostGuard->assertAllowed($this->credentials->smtpHost);

        $transport = new EsmtpTransport(
            $this->credentials->smtpHost,
            $this->credentials->smtpPort,
            tls: $this->credentials->smtpEncryption === MailEncryption::Ssl,
        );

        match ($this->credentials->smtpEncryption) {
            // STARTTLS obligatorio: si el servidor no lo ofrece, no se envía nada en claro.
            MailEncryption::StartTls => $transport->setRequireTls(true),
            // Sin cifrado (solo servidores locales/de pruebas): ni siquiera intentarlo.
            MailEncryption::None => $transport->setAutoTls(false),
            MailEncryption::Ssl => null,
        };

        /** @var SocketStream $stream */
        $stream = $transport->getStream();
        $stream->setTimeout($this->timeout);

        $transport->setUsername($this->credentials->smtpUsername);
        $transport->setPassword($this->credentials->smtpPassword);

        try {
            $transport->start();
        } catch (TransportExceptionInterface $e) {
            throw $this->translate($e);
        }

        return $this->transport = $transport;
    }

    /**
     * Comprueba que se puede conectar e iniciar sesión.
     *
     * @throws MailConnectionException
     * @throws MailAuthenticationException
     */
    public function verify(): void
    {
        $this->transport();
    }

    public function disconnect(): void
    {
        try {
            $this->transport?->stop();
        } catch (Throwable) {
            // Cerrar una conexión ya rota no es un error.
        } finally {
            $this->transport = null;
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    /** Autenticación rechazada (código 535 o fallo de todos los autenticadores) o problema de conexión. */
    public function translate(TransportExceptionInterface $e): MailConnectionException|MailAuthenticationException
    {
        if ($e->getCode() === 535 || str_contains($e->getMessage(), 'Failed to authenticate')) {
            return new MailAuthenticationException(
                "El servidor SMTP rechazó el usuario o la contraseña de {$this->credentials->smtpUsername}.",
                previous: $e,
            );
        }

        return new MailConnectionException(sprintf(
            'No se pudo conectar con el servidor SMTP %s:%d (%s).',
            $this->credentials->smtpHost,
            $this->credentials->smtpPort,
            $this->credentials->smtpEncryption->value,
        ), previous: $e);
    }
}
