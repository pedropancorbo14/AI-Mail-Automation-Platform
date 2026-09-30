<?php

namespace App\Mail\Providers\Imap;

use App\Mail\Data\MailAddress;
use App\Mail\Data\MailAttachment;
use App\Mail\Data\MailMessage;
use Carbon\CarbonImmutable;
use DirectoryTree\ImapEngine\Address;
use DirectoryTree\ImapEngine\Attachment;
use DirectoryTree\ImapEngine\MessageInterface;

/**
 * Convierte un mensaje de ImapEngine en nuestro DTO MailMessage.
 *
 * Adjuntos: su id es la posición dentro del mensaje ("0", "1"...). Un
 * mensaje IMAP es inmutable, así que la posición es estable.
 */
class ImapMessageMapper
{
    public static function toMailMessage(MessageInterface $message, string $folder): MailMessage
    {
        $replyTo = $message->replyTo();

        return new MailMessage(
            id: (string) $message->uid(),
            folder: $folder,
            messageId: self::stripAngles($message->messageId()),
            subject: $message->subject() ?? '',
            from: self::address($message->from()),
            to: self::addresses($message->to()),
            cc: self::addresses($message->cc()),
            replyTo: $replyTo ? [self::address($replyTo)] : [],
            date: $message->date() ? CarbonImmutable::instance($message->date()) : null,
            textBody: $message->text(),
            htmlBody: $message->html(),
            attachments: self::attachments($message),
            inReplyTo: self::stripAngles($message->inReplyTo()[0] ?? null),
            references: self::references($message),
            seen: in_array('\\seen', array_map(strtolower(...), $message->flags()), true),
        );
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
