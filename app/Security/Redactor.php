<?php

namespace App\Security;

/**
 * Oculta secretos antes de guardarlos en logs o en la auditoría.
 *
 *  - Por nombre de campo: cualquier clave que contenga password, secret,
 *    token, credential, api_key, authorization o cookie (también anidada).
 *  - Por forma, dentro de textos: cabeceras "Bearer …", claves de Anthropic
 *    (sk-ant-…), tokens de Sanctum (id|40 caracteres) y contraseñas en URLs
 *    (esquema://usuario:contraseña@host).
 */
final class Redactor
{
    public const string HIDDEN = '[oculto]';

    /** Partes de nombre de campo que delatan un secreto. */
    private const array SECRET_KEYS = ['password', 'secret', 'token', 'credential', 'api_key', 'apikey', 'authorization', 'cookie'];

    /** Secretos reconocibles por su forma => sustitución. */
    private const array SECRET_PATTERNS = [
        '/\bBearer\s+[A-Za-z0-9._~+\/=|-]{8,}/i' => 'Bearer ' . self::HIDDEN,
        '/\bsk-ant-[A-Za-z0-9_-]{8,}/' => self::HIDDEN,
        '/\b\d+\|[A-Za-z0-9]{40,}\b/' => self::HIDDEN,
        '~(\b[a-z][a-z0-9+.-]*://[^\s:/@]+):[^\s@/]+@~i' => '$1:' . self::HIDDEN . '@',
    ];

    public static function isSecretKey(string|int $key): bool
    {
        $name = strtolower((string) $key);

        foreach (self::SECRET_KEYS as $secret) {
            if (str_contains($name, $secret)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            $data[$key] = match (true) {
                self::isSecretKey($key) => self::HIDDEN,
                is_array($value) => self::redact($value),
                is_string($value) => self::redactString($value),
                default => $value,
            };
        }

        return $data;
    }

    public static function redactString(string $text): string
    {
        return (string) preg_replace(array_keys(self::SECRET_PATTERNS), array_values(self::SECRET_PATTERNS), $text);
    }
}
