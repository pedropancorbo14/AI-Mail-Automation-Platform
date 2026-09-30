<?php

namespace App\Mail\Providers\Imap;

use App\Mail\Credentials\ImapSmtpCredentials;
use App\Mail\Enums\MailEncryption;
use App\Mail\Exceptions\MailConnectionException;
use App\Mail\Security\MailHostGuard;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionException;
use DirectoryTree\ImapEngine\Exceptions\ImapStreamException;
use DirectoryTree\ImapEngine\Mailbox;
use Throwable;

/**
 * Conexión IMAP de una cuenta. Envuelve ImapEngine para que el resto del
 * código no dependa de la librería y traduce sus errores a nuestras
 * excepciones. La conexión se abre al primer uso y se reutiliza.
 */
class ImapClient
{
    private ?Mailbox $mailbox = null;

    public function __construct(
        private readonly ImapSmtpCredentials $credentials,
        private readonly MailHostGuard $hostGuard,
        private readonly int $timeout = 15,
    ) {
    }

    /**
     * Conecta (TCP + TLS/STARTTLS + login) si no lo estaba ya.
     *
     * @throws MailConnectionException
     */
    public function mailbox(): Mailbox
    {
        if ($this->mailbox?->connected()) {
            return $this->mailbox;
        }

        // Anti-SSRF: se repite aquí porque el DNS puede haber cambiado desde que se validó.
        $this->hostGuard->assertAllowed($this->credentials->imapHost);

        $mailbox = Mailbox::make([
            'host' => $this->credentials->imapHost,
            'port' => $this->credentials->imapPort,
            'encryption' => self::encryption($this->credentials->imapEncryption),
            'validate_cert' => true,
            'timeout' => $this->timeout,
            'username' => $this->credentials->imapUsername,
            'password' => $this->credentials->imapPassword,
        ]);

        try {
            $mailbox->connect();
        } catch (ImapConnectionException|ImapStreamException $e) {
            throw $this->connectionFailed($e);
        }

        return $this->mailbox = $mailbox;
    }

    public function disconnect(): void
    {
        $this->mailbox?->disconnect();
        $this->mailbox = null;
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    /** Valor de "encryption" que espera ImapEngine ('' = TCP sin cifrar). */
    private static function encryption(MailEncryption $encryption): string
    {
        return match ($encryption) {
            MailEncryption::Ssl => 'ssl',
            MailEncryption::StartTls => 'starttls',
            MailEncryption::None => '',
        };
    }

    private function connectionFailed(Throwable $e): MailConnectionException
    {
        return new MailConnectionException(sprintf(
            'No se pudo conectar con el servidor IMAP %s:%d (%s).',
            $this->credentials->imapHost,
            $this->credentials->imapPort,
            $this->credentials->imapEncryption->value,
        ), previous: $e);
    }
}
