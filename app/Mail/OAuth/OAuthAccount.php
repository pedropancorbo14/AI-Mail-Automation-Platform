<?php

namespace App\Mail\OAuth;

use App\Mail\Credentials\OAuthCredentials;

/** Cuenta autorizada tras el flujo OAuth: quién es y sus tokens. */
final readonly class OAuthAccount
{
    public function __construct(
        public string $email,
        public ?string $name,
        public OAuthCredentials $credentials,
    ) {
    }
}
