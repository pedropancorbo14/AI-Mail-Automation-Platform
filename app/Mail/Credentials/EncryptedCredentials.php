<?php

namespace App\Mail\Credentials;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

/**
 * Cast de Eloquent: guarda MailCredentials como JSON cifrado con la APP_KEY
 * (AES-256 + MAC: si alguien modifica el valor en BD, descifrar falla).
 * El tipo va dentro del JSON cifrado para reconstruir la clase correcta.
 * Rotación de clave: APP_PREVIOUS_KEYS permite seguir leyendo lo cifrado
 * con la clave anterior.
 *
 * @implements CastsAttributes<MailCredentials, MailCredentials>
 */
class EncryptedCredentials implements CastsAttributes
{
    /** @var array<string, class-string<MailCredentials>> */
    private const array TYPES = [
        'imap_smtp' => ImapSmtpCredentials::class,
        'oauth' => OAuthCredentials::class,
    ];

    public function get(Model $model, string $key, mixed $value, array $attributes): ?MailCredentials
    {
        if ($value === null) {
            return null;
        }

        $payload = json_decode(Crypt::decryptString($value), true, flags: JSON_THROW_ON_ERROR);
        $class = self::TYPES[$payload['type']] ?? throw new InvalidArgumentException("Tipo de credenciales desconocido: {$payload['type']}");

        return $class::fromArray($payload['data']);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof MailCredentials) {
            throw new InvalidArgumentException('Las credenciales deben ser una instancia de MailCredentials.');
        }

        return Crypt::encryptString(json_encode(
            ['type' => $value::type(), 'data' => $value->toArray()],
            JSON_THROW_ON_ERROR,
        ));
    }
}
