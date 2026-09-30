<?php

namespace App\Mail\Credentials;

use App\Mail\Enums\MailEncryption;
use SensitiveParameter;

/**
 * IMAP (recepción) y SMTP (envío) por separado: muchos proveedores usan
 * hosts, puertos o incluso usuarios distintos para cada uno.
 */
final readonly class ImapSmtpCredentials implements MailCredentials
{
    use RedactsSecrets;

    public function __construct(
        public string $imapHost,
        public int $imapPort,
        public MailEncryption $imapEncryption,
        public string $imapUsername,
        #[SensitiveParameter] public string $imapPassword,
        public string $smtpHost,
        public int $smtpPort,
        public MailEncryption $smtpEncryption,
        public string $smtpUsername,
        #[SensitiveParameter] public string $smtpPassword,
    ) {
    }

    public static function type(): string
    {
        return 'imap_smtp';
    }

    protected static function secretKeys(): array
    {
        return ['imap_password', 'smtp_password'];
    }

    public function toArray(): array
    {
        return [
            'imap_host' => $this->imapHost,
            'imap_port' => $this->imapPort,
            'imap_encryption' => $this->imapEncryption->value,
            'imap_username' => $this->imapUsername,
            'imap_password' => $this->imapPassword,
            'smtp_host' => $this->smtpHost,
            'smtp_port' => $this->smtpPort,
            'smtp_encryption' => $this->smtpEncryption->value,
            'smtp_username' => $this->smtpUsername,
            'smtp_password' => $this->smtpPassword,
        ];
    }

    public static function fromArray(#[SensitiveParameter] array $data): static
    {
        return new self(
            imapHost: $data['imap_host'],
            imapPort: (int) $data['imap_port'],
            imapEncryption: MailEncryption::from($data['imap_encryption']),
            imapUsername: $data['imap_username'],
            imapPassword: $data['imap_password'],
            smtpHost: $data['smtp_host'],
            smtpPort: (int) $data['smtp_port'],
            smtpEncryption: MailEncryption::from($data['smtp_encryption']),
            smtpUsername: $data['smtp_username'],
            smtpPassword: $data['smtp_password'],
        );
    }
}
