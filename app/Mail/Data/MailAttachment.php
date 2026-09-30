<?php

namespace App\Mail\Data;

/**
 * Metadatos de un adjunto. El contenido se descarga aparte con
 * MailProvider::downloadAttachment() para no cargar ficheros grandes al
 * listar mensajes.
 */
final readonly class MailAttachment
{
    public function __construct(
        public string $id,
        public string $filename,
        public string $contentType,
        public int $size,
        public bool $inline = false,
        public ?string $contentId = null,
    ) {
    }
}
