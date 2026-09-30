<?php

namespace App\Mail\Credentials;

use JsonSerializable;

/**
 * Credenciales de una conexión de correo. Se guardan cifradas (cast
 * EncryptedCredentials) y nunca deben salir en respuestas ni logs:
 * jsonSerialize() y __debugInfo() devuelven una versión sin secretos; solo
 * toArray() (uso explícito, para cifrar) contiene los valores reales.
 */
interface MailCredentials extends JsonSerializable
{
    /** Identificador del tipo guardado junto a los datos cifrados. */
    public static function type(): string;

    /** @return array<string, mixed> Valores reales, incluidos los secretos. */
    public function toArray(): array;

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): static;
}
