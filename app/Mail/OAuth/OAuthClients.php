<?php

namespace App\Mail\OAuth;

use App\Mail\Enums\MailProviderType;
use App\Mail\Exceptions\UnsupportedMailProviderException;
use App\Mail\OAuth\Contracts\OAuthClient;

/** Clientes OAuth disponibles por tipo de proveedor (Microsoft, Google). */
class OAuthClients
{
    /** @var array<string, OAuthClient> */
    private array $clients = [];

    public function register(OAuthClient $client): void
    {
        $this->clients[$client->provider()->value] = $client;
    }

    /**
     * @throws UnsupportedMailProviderException
     */
    public function for(MailProviderType $type): OAuthClient
    {
        return $this->clients[$type->value]
            ?? throw new UnsupportedMailProviderException("El proveedor {$type->value} no se conecta con OAuth.");
    }
}
