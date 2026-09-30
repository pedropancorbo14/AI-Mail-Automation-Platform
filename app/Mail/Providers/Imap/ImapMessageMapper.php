<?php

namespace App\Mail\Providers\Imap;

use App\Mail\Data\MailMessage;
use App\Mail\Support\MimeMessageMapper;
use DirectoryTree\ImapEngine\MessageInterface;

/**
 * Mensaje IMAP → MailMessage: el id es el UID y "leído" es el flag \Seen.
 * El resto del mapeo es común a cualquier mensaje MIME (MimeMessageMapper).
 */
class ImapMessageMapper
{
    public static function toMailMessage(MessageInterface $message, string $folder): MailMessage
    {
        return MimeMessageMapper::toMailMessage(
            $message,
            $folder,
            id: (string) $message->uid(),
            seen: in_array('\\seen', array_map(strtolower(...), $message->flags()), true),
        );
    }
}
