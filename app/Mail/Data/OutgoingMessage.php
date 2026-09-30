<?php

namespace App\Mail\Data;

use InvalidArgumentException;

/**
 * Email a enviar. El remitente es siempre la cuenta de la conexión: no se
 * permite elegir otro "From" (evita suplantar direcciones).
 */
final readonly class OutgoingMessage
{
    /**
     * @param  list<MailAddress>  $to
     * @param  list<MailAddress>  $cc
     * @param  list<MailAddress>  $bcc
     * @param  string|null  $inReplyTo  Message-ID (sin < >) del mensaje al que se responde.
     * @param  list<string>  $references  Message-IDs del hilo (sin < >).
     * @param  list<OutgoingAttachment>  $attachments
     */
    public function __construct(
        public array $to,
        public string $subject,
        public ?string $textBody = null,
        public ?string $htmlBody = null,
        public array $cc = [],
        public array $bcc = [],
        public ?string $inReplyTo = null,
        public array $references = [],
        public array $attachments = [],
    ) {
        if ($to === [] && $cc === [] && $bcc === []) {
            throw new InvalidArgumentException('El email necesita al menos un destinatario.');
        }

        if ($textBody === null && $htmlBody === null) {
            throw new InvalidArgumentException('El email necesita cuerpo en texto o en HTML.');
        }
    }
}
