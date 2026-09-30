<?php

namespace Tests\Support;

use App\Mail\Credentials\ImapSmtpCredentials;
use App\Mail\Enums\MailEncryption;
use App\Mail\Providers\Imap\ImapClient;
use App\Mail\Providers\Imap\ImapSmtpProvider;
use App\Mail\Security\MailHostGuard;
use GuzzleHttp\Client;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

/**
 * Acceso al servidor GreenMail (IMAP 3143 / SMTP 3025 sin TLS, API REST
 * 8080) para los tests de IMAP/SMTP contra un servidor real. Cada test crea
 * sus propias cuentas, así no comparten buzones.
 *
 * Host: "greenmail" dentro de Docker; en CI se sobrescribe con GREENMAIL_HOST.
 */
class GreenMail
{
    public static function host(): string
    {
        return env('GREENMAIL_HOST', 'greenmail');
    }

    /** Crea una cuenta nueva (email = usuario de login) y devuelve su email. */
    public static function createAccount(string $password = 'secret'): string
    {
        $email = 'u' . Str::lower(Str::random(12)) . '@greenmail.test';

        // Guzzle directo (no Http::): así un Http::fake() del test no intercepta la llamada.
        (new Client(['timeout' => 5]))->post(
            'http://' . self::host() . ':' . env('GREENMAIL_API_PORT', 8080) . '/api/user',
            ['json' => ['email' => $email, 'login' => $email, 'password' => $password]],
        );

        return $email;
    }

    public static function credentials(string $email, string $password = 'secret'): ImapSmtpCredentials
    {
        return new ImapSmtpCredentials(
            imapHost: self::host(), imapPort: (int) env('GREENMAIL_IMAP_PORT', 3143), imapEncryption: MailEncryption::None,
            imapUsername: $email, imapPassword: $password,
            smtpHost: self::host(), smtpPort: (int) env('GREENMAIL_SMTP_PORT', 3025), smtpEncryption: MailEncryption::None,
            smtpUsername: $email, smtpPassword: $password,
        );
    }

    public static function imapClient(string $email, string $password = 'secret'): ImapClient
    {
        return new ImapClient(self::credentials($email, $password), new MailHostGuard(true), 5);
    }

    /** Proveedor IMAP/SMTP completo para una cuenta de GreenMail. */
    public static function provider(string $email, string $password = 'secret'): ImapSmtpProvider
    {
        return new ImapSmtpProvider(self::imapClient($email, $password));
    }

    /**
     * Mete un email en un buzón con IMAP APPEND (como si hubiera llegado),
     * sin depender del SMTP. Devuelve el UID asignado.
     *
     * @param  list<string>  $flags  p. ej. ['\Seen']
     */
    public static function append(string $email, Email $message, string $folder = 'INBOX', array $flags = []): int
    {
        $client = self::imapClient($email);

        try {
            return $client->mailbox()->folders()->firstOrCreate($folder)->messages()->append($message->toString(), $flags ?: null);
        } finally {
            $client->disconnect();
        }
    }
}
