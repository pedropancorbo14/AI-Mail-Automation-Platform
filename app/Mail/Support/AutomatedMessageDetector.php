<?php

namespace App\Mail\Support;

/**
 * Detecta correos que no ha escrito una persona (rebotes, respuestas
 * automáticas, listas, boletines). La automatización no responde nunca a
 * estos correos: contestar a una respuesta automática puede crear un bucle
 * infinito entre dos sistemas.
 *
 * Solo mira cabeceras estándar y el remitente; no usa la IA (es gratis y
 * determinista). Común a todos los proveedores: cada uno le pasa sus
 * cabeceras.
 */
class AutomatedMessageDetector
{
    /** Cabeceras que necesita (los proveedores solo tienen que leer estas). */
    public const array HEADERS = [
        'Auto-Submitted', 'Precedence', 'List-Id', 'List-Unsubscribe', 'Content-Type',
        'X-Autoreply', 'X-Autorespond', 'X-Autoreply-From', 'X-Mail-Autoreply',
    ];

    private const array AUTOREPLY_HEADERS = ['x-autoreply', 'x-autorespond', 'x-autoreply-from', 'x-mail-autoreply'];

    private const array BULK_PRECEDENCE = ['bulk', 'list', 'junk', 'auto_reply'];

    /** Parte local (antes de @) de direcciones que no aceptan respuestas. */
    private const string NO_REPLY_SENDER = '/^(mailer-daemon|postmaster|no[-_.]?reply|do[-_.]?not[-_.]?reply|bounces?)([+._-].*)?$/i';

    /**
     * Motivo por el que el correo es automático, o null si parece escrito
     * por una persona.
     *
     * @param  array<string, string|null>  $headers  nombre de cabecera (sin importar mayúsculas) => valor
     */
    public static function reason(array $headers, ?string $fromEmail): ?string
    {
        $headers = array_change_key_case(array_filter(
            array_map(fn (?string $value) => $value === null ? null : trim($value), $headers),
            fn (?string $value) => $value !== null,
        ));

        // RFC 3834: cualquier valor distinto de "no" es un mensaje automático.
        $autoSubmitted = strtolower(trim(strtok($headers['auto-submitted'] ?? '', ';') ?: ''));

        if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
            return "Mensaje automático (Auto-Submitted: {$autoSubmitted}).";
        }

        if (str_starts_with(strtolower($headers['content-type'] ?? ''), 'multipart/report')) {
            return 'Aviso de entrega o rebote (multipart/report).';
        }

        $precedence = strtolower($headers['precedence'] ?? '');

        if (in_array($precedence, self::BULK_PRECEDENCE, true)) {
            return "Envío masivo o automático (Precedence: {$precedence}).";
        }

        if (isset($headers['list-id']) || isset($headers['list-unsubscribe'])) {
            return 'Lista de correo o boletín (List-Id / List-Unsubscribe).';
        }

        foreach (self::AUTOREPLY_HEADERS as $name) {
            if (isset($headers[$name])) {
                return "Respuesta automática (cabecera {$name}).";
            }
        }

        $localPart = $fromEmail === null ? '' : strstr($fromEmail, '@', true);

        if ($localPart !== false && preg_match(self::NO_REPLY_SENDER, $localPart)) {
            return "La dirección del remitente no acepta respuestas ({$fromEmail}).";
        }

        return null;
    }
}
