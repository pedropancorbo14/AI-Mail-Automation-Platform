<?php

namespace App\Billing\Stripe;

/**
 * Comprueba la cabecera Stripe-Signature de un webhook: "t=<unix>,v1=<hex>"
 * con v1 = HMAC-SHA256("t.<cuerpo>", secreto). Rechaza firmas viejas (más de
 * $tolerance segundos) para que nadie repita un aviso capturado.
 */
final class StripeSignature
{
    public static function valid(string $payload, ?string $header, string $secret, int $tolerance, ?int $now = null): bool
    {
        if ($header === null || $secret === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === [] || abs(($now ?? time()) - $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /** Cabecera para un cuerpo (tests y pruebas locales). */
    public static function header(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
    }
}
