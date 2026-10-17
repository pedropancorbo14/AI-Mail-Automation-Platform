<?php

namespace App\AI\Remote\Data;

/** Una entrada de un stream de Redis leída por un consumer group. */
final readonly class StreamMessage
{
    /** @param  array<string, string>  $fields */
    public function __construct(
        public string $stream,
        public string $id,
        public array $fields,
        public int $deliveries = 1,
    ) {
    }

    public function payload(): string
    {
        return $this->fields['payload'] ?? '';
    }
}
