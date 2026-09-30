<?php

namespace App\Mail\Enums;

use App\Mail\Credentials\ImapSmtpCredentials;
use App\Mail\Credentials\MailCredentials;
use App\Mail\Credentials\OAuthCredentials;

enum MailProviderType: string
{
    case Imap = 'imap';
    case Microsoft = 'microsoft';
    case Google = 'google';

    /** @return class-string<MailCredentials> */
    public function credentialsClass(): string
    {
        return match ($this) {
            self::Imap => ImapSmtpCredentials::class,
            self::Microsoft, self::Google => OAuthCredentials::class,
        };
    }

    public function usesOAuth(): bool
    {
        return $this->credentialsClass() === OAuthCredentials::class;
    }
}
