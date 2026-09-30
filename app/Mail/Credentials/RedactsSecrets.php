<?php

namespace App\Mail\Credentials;

/**
 * Sustituye los campos secretos por "********" en json_encode(), var_dump(),
 * print_r() y logs. La clase declara qué claves de toArray() son secretas.
 */
trait RedactsSecrets
{
    /** @return list<string> */
    abstract protected static function secretKeys(): array;

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->redacted();
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->redacted();
    }

    /** @return array<string, mixed> */
    private function redacted(): array
    {
        $data = $this->toArray();

        foreach (static::secretKeys() as $key) {
            if (($data[$key] ?? null) !== null) {
                $data[$key] = '********';
            }
        }

        return $data;
    }
}
