<?php

namespace App\Mail\Data;

use Carbon\CarbonImmutable;

final readonly class MailMessage
{
    /**
     * @param  string  $id  Identificador en el proveedor (UID IMAP, id Graph/Gmail).
     * @param  string|null  $messageId  Cabecera Message-ID (RFC 5322), sin < >. Base de la deduplicación (Fase 4).
     * @param  list<MailAddress>  $to
     * @param  list<MailAddress>  $cc
     * @param  list<MailAddress>  $replyTo
     * @param  list<string>  $references  Message-IDs del hilo, sin < >.
     * @param  list<MailAttachment>  $attachments
     */
    public function __construct(
        public string $id,
        public string $folder,
        public ?string $messageId,
        public string $subject,
        public ?MailAddress $from,
        public array $to,
        public array $cc,
        public array $replyTo,
        public ?CarbonImmutable $date,
        public ?string $textBody,
        public ?string $htmlBody,
        public array $attachments = [],
        public ?string $inReplyTo = null,
        public array $references = [],
        public bool $seen = false,
    ) {
    }
}
