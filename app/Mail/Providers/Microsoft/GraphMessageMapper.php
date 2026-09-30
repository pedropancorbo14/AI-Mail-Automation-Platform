<?php

namespace App\Mail\Providers\Microsoft;

use App\Mail\Data\MailAddress;
use App\Mail\Data\MailAttachment;
use App\Mail\Data\MailMessage;
use App\Mail\Support\HtmlToText;
use Carbon\CarbonImmutable;

/**
 * Convierte un mensaje de Microsoft Graph (JSON) en nuestro MailMessage.
 */
class GraphMessageMapper
{
    /** Campos a pedir en $select para construir MailMessage. */
    public const string SELECT = 'id,internetMessageId,subject,from,toRecipients,ccRecipients,replyTo,'
        . 'receivedDateTime,body,isRead,internetMessageHeaders';

    /** Metadatos de adjuntos (sin contenido) para $expand. */
    public const string EXPAND = 'attachments($select=id,name,contentType,size,isInline)';

    /** @param  array<string, mixed>  $message */
    public static function toMailMessage(array $message, string $folder): MailMessage
    {
        $headers = self::headers($message['internetMessageHeaders'] ?? []);
        $isHtml = strtolower($message['body']['contentType'] ?? 'html') === 'html';
        $content = $message['body']['content'] ?? null;

        return new MailMessage(
            id: $message['id'],
            folder: $folder,
            messageId: self::stripAngles($message['internetMessageId'] ?? null),
            subject: $message['subject'] ?? '',
            from: self::address($message['from'] ?? null),
            to: self::addresses($message['toRecipients'] ?? []),
            cc: self::addresses($message['ccRecipients'] ?? []),
            replyTo: self::addresses($message['replyTo'] ?? []),
            date: isset($message['receivedDateTime']) ? CarbonImmutable::parse($message['receivedDateTime']) : null,
            // Graph da el cuerpo en un solo formato: si es HTML, el texto se deriva de él.
            textBody: $isHtml ? ($content === null ? null : HtmlToText::convert($content)) : $content,
            htmlBody: $isHtml ? $content : null,
            attachments: array_map(self::attachment(...), $message['attachments'] ?? []),
            inReplyTo: self::stripAngles($headers['in-reply-to'] ?? null),
            references: self::references($headers['references'] ?? null),
            seen: (bool) ($message['isRead'] ?? false),
        );
    }

    /** @param  array<string, mixed>  $attachment */
    public static function attachment(array $attachment): MailAttachment
    {
        return new MailAttachment(
            id: $attachment['id'],
            filename: $attachment['name'] ?? 'adjunto',
            contentType: $attachment['contentType'] ?? 'application/octet-stream',
            size: (int) ($attachment['size'] ?? 0),
            inline: (bool) ($attachment['isInline'] ?? false),
        );
    }

    /** @param  array{emailAddress?: array{address?: string, name?: string}}|null  $recipient */
    private static function address(?array $recipient): ?MailAddress
    {
        $email = $recipient['emailAddress']['address'] ?? null;

        return $email ? new MailAddress($email, ($recipient['emailAddress']['name'] ?? null) ?: null) : null;
    }

    /** @return list<MailAddress> */
    private static function addresses(array $recipients): array
    {
        return array_values(array_filter(array_map(self::address(...), $recipients)));
    }

    /** @return array<string, string> nombre de cabecera en minúsculas => valor */
    private static function headers(array $headers): array
    {
        $map = [];

        foreach ($headers as $header) {
            $map[strtolower($header['name'] ?? '')] = $header['value'] ?? '';
        }

        return $map;
    }

    /** @return list<string> */
    private static function references(?string $value): array
    {
        if (! $value) {
            return [];
        }

        preg_match_all('/<([^>]+)>/', $value, $matches);

        return $matches[1];
    }

    private static function stripAngles(?string $id): ?string
    {
        $id = $id === null ? null : trim($id, " <>\t\r\n");

        return $id === '' ? null : $id;
    }
}
