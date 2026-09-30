<?php

namespace App\Mail\Data;

final readonly class OutgoingAttachment
{
    public function __construct(
        public string $filename,
        public string $content,
        public string $contentType = 'application/octet-stream',
    ) {
    }
}
