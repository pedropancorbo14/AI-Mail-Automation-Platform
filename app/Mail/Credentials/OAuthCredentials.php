<?php

namespace App\Mail\Credentials;

use Carbon\CarbonImmutable;
use SensitiveParameter;

/** Tokens OAuth 2.0 (Microsoft Graph, Gmail API). */
final readonly class OAuthCredentials implements MailCredentials
{
    use RedactsSecrets;

    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        #[SensitiveParameter] public string $accessToken,
        #[SensitiveParameter] public ?string $refreshToken,
        public CarbonImmutable $expiresAt,
        public array $scopes = [],
    ) {
    }

    public static function type(): string
    {
        return 'oauth';
    }

    protected static function secretKeys(): array
    {
        return ['access_token', 'refresh_token'];
    }

    /** Caducado o a punto de caducar (margen para no usar un token que expira en pleno uso). */
    public function isExpired(int $marginSeconds = 60): bool
    {
        return $this->expiresAt->subSeconds($marginSeconds)->isPast();
    }

    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_at' => $this->expiresAt->toIso8601String(),
            'scopes' => $this->scopes,
        ];
    }

    public static function fromArray(#[SensitiveParameter] array $data): static
    {
        return new self(
            accessToken: $data['access_token'],
            refreshToken: $data['refresh_token'] ?? null,
            expiresAt: CarbonImmutable::parse($data['expires_at']),
            scopes: $data['scopes'] ?? [],
        );
    }
}
