<?php

namespace App\Mail\Autodetect;

/**
 * Configuración propuesta para una dirección de email.
 *
 * source: ispdb (base de datos de Thunderbird) | domain (autoconfig del
 * dominio) | srv (DNS) | guess (nombres habituales: menos fiable) | null
 * (no se encontró nada). providerHint: microsoft | google | null (según
 * los MX: con ellos es mejor conectar con OAuth).
 */
final readonly class AutodetectResult
{
    /**
     * @param  array{host: string, port: int, encryption: string, username: string}|null  $imap
     * @param  array{host: string, port: int, encryption: string, username: string}|null  $smtp
     */
    public function __construct(
        public ?string $source,
        public ?string $providerHint,
        public ?array $imap,
        public ?array $smtp,
    ) {
    }

    public function withHint(?string $hint): self
    {
        return new self($this->source, $hint, $this->imap, $this->smtp);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'found' => $this->imap !== null,
            'source' => $this->source,
            'provider_hint' => $this->providerHint,
            'imap' => $this->imap,
            'smtp' => $this->smtp,
        ];
    }
}
