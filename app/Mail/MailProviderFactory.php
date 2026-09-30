<?php

namespace App\Mail;

use App\Mail\Contracts\MailProvider;
use App\Mail\Enums\MailProviderType;
use App\Mail\Exceptions\UnsupportedMailProviderException;
use App\Models\MailConnection;
use Closure;

/**
 * Devuelve el MailProvider de una conexión según su tipo. Cada
 * implementación se registra con extend() en MailProvidersServiceProvider;
 * en tests se puede sustituir cualquier tipo por un fake:
 *
 *   app(MailProviderFactory::class)->extend(MailProviderType::Imap, fn () => $fake);
 */
class MailProviderFactory
{
    /** @var array<string, Closure(MailConnection): MailProvider> */
    private array $resolvers = [];

    /** @param  Closure(MailConnection): MailProvider  $resolver */
    public function extend(MailProviderType $type, Closure $resolver): void
    {
        $this->resolvers[$type->value] = $resolver;
    }

    /**
     * @throws UnsupportedMailProviderException
     */
    public function make(MailConnection $connection): MailProvider
    {
        $resolver = $this->resolvers[$connection->provider->value]
            ?? throw new UnsupportedMailProviderException(
                "El proveedor de correo {$connection->provider->value} todavía no está disponible.",
            );

        return $resolver($connection);
    }

    public function supports(MailProviderType $type): bool
    {
        return isset($this->resolvers[$type->value]);
    }
}
