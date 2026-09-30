<?php

namespace App\Mail\Data;

final readonly class AttachmentContent
{
    public function __construct(
        public MailAttachment $attachment,
        public string $content,
    ) {
    }
}
