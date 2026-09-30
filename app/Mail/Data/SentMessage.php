<?php

namespace App\Mail\Data;

final readonly class SentMessage
{
    /**
     * @param  string|null  $messageId  Message-ID asignado (sin < >), para enlazar respuestas e hilos.
     */
    public function __construct(
        public ?string $messageId,
    ) {
    }
}
