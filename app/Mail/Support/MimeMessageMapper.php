<?php

namespace App\Mail\Support;

use App\Mail\Data\AttachmentContent;
use App\Mail\Data\MailAddress;
use App\Mail\Data\MailAttachment;
use App\Mail\Data\MailMessage;
use App\Mail\Exceptions\MailNotFoundException;
use App\Mail\Exceptions\MailProviderException;
use Carbon\CarbonImmutable;
use DirectoryTree\ImapEngine\Address;
use DirectoryTree\ImapEngine\Attachment;
use DirectoryTree\ImapEngine\MessageInterface;

/**
 * Convierte un mensaje MIME ya parseado (ImapEngine: Message de IMAP o
 * FileMessage a partir de MIME crudo, p. ej. Gmail format=raw) en nuestro
 * MailMessage. Así IMAP y Gmail interpretan igual direcciones, hilos y
 * adjuntos.
 *
 * Adjuntos: su id es la posición dentro del mensaje ("0", "1"...), en el
 * orden de la estructura MIME. Un mensaje es inmutable: la posición es estable.
 */
class MimeMessageMapper
{
    public static function toMailMessage(MessageInterface $message, string $folder, string $id, bool $seen): MailMessage
    {
        $replyTo = $message->replyTo();

        return new MailMessage(
            id: $id,
            folder: $folder,
            messageId: self::stripAngles($message->messageId()),
            subject: $message->subject() ?? '',
            from: self::address($message->from()),
            to: self::addresses($message->to()),
            cc: self::addresses($message->cc()),
            replyTo: $replyTo ? array_values(array_filter([self::address($replyTo)])) : [],
            date: $message->date() ? CarbonImmutable::instance($message->date()) : null,
            textBody: $message->text(),
            htmlBody: $message->html(),
            attachments: self::attachments($message),
            inReplyTo: self::stripAngles($message->inReplyTo()[0] ?? null),
            references: self::references($message),
            seen: $seen,
            automatedReason: AutomatedMessageDetector::reason(self::headers($message), $message->from()?->email()),
        );
    }

    /**
     * Valores de las cabeceras que usa AutomatedMessageDetector.
     *
     * @return array<string, string|null>
     */
    private static function headers(MessageInterface $message): array
    {
        $values = [];

        foreach (AutomatedMessageDetector::HEADERS as $name) {
            $values[$name] = $message->header($name)?->getValue();
        }

        return $values;
    }

    /** @return list<MailAttachment> */
    public static function attachments(MessageInterface $message): array
    {
        $attachments = [];

        foreach (array_values($message->attachments()) as $index => $attachment) {
            $attachments[] = self::attachment($attachment, (string) $index);
        }

        return $attachments;
    }

    public static function attachment(Attachment $attachment, string $id): MailAttachment
    {
        return new MailAttachment(
            id: $id,
            filename: $attachment->filename() ?? "adjunto-{$id}",
            contentType: $attachment->contentType(),
            size: strlen($attachment->contents()),
            inline: strtolower($attachment->contentDisposition()) === 'inline',
            contentId: self::stripAngles($attachment->contentId()),
        );
    }

    /**
     * Contenido de un adjunto por id (posición), con límite de tamaño.
     *
     * @throws MailNotFoundException
     * @throws MailProviderException
     */
    public static function download(MessageInterface $message, string $messageId, string $attachmentId, int $maxBytes): AttachmentContent
    {
        $attachment = ctype_digit($attachmentId) ? (array_values($message->attachments())[(int) $attachmentId] ?? null) : null;

        if ($attachment === null) {
            throw new MailNotFoundException("El adjunto {$attachmentId} no existe en el mensaje {$messageId}.");
        }

        $content = $attachment->contents();

        if (strlen($content) > $maxBytes) {
            throw new MailProviderException(sprintf(
                'El adjunto %s supera el tamaño máximo permitido (%d MB).',
                $attachment->filename() ?? $attachmentId,
                intdiv($maxBytes, 1024 * 1024),
            ));
        }

        return new AttachmentContent(self::attachment($attachment, $attachmentId), $content);
    }

    private static function address(?Address $address): ?MailAddress
    {
        if ($address === null || $address->email() === '') {
            return null;
        }

        return new MailAddress($address->email(), $address->name() ?: null);
    }

    /**
     * @param  array<Address>  $addresses
     * @return list<MailAddress>
     */
    private static function addresses(array $addresses): array
    {
        return array_values(array_filter(array_map(self::address(...), $addresses)));
    }

    /** @return list<string> */
    private static function references(MessageInterface $message): array
    {
        $header = $message->header('References');

        if ($header === null) {
            return [];
        }

        $ids = array_map(fn ($part) => self::stripAngles($part->getValue()), $header->getParts());

        return array_values(array_filter($ids));
    }

    private static function stripAngles(?string $id): ?string
    {
        $id = $id === null ? null : trim($id, " <>\t\r\n");

        return $id === '' ? null : $id;
    }
}
