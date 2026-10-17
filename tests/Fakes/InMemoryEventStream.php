<?php

namespace Tests\Fakes;

use App\AI\Remote\Contracts\EventStream;
use App\AI\Remote\Data\StreamMessage;
use RuntimeException;

/** Streams en memoria para los tests (misma semántica básica que Redis). */
class InMemoryEventStream implements EventStream
{
    /** @var array<string, list<StreamMessage>> */
    public array $entries = [];

    /** @var array<string, list<StreamMessage>> Entregadas y sin confirmar, por stream. */
    public array $pending = [];

    /** @var list<array{message: StreamMessage, reason: string}> */
    public array $deadLettered = [];

    public ?RuntimeException $failPublishWith = null;

    private int $sequence = 0;

    public function publish(string $stream, array $fields): string
    {
        if ($this->failPublishWith) {
            throw $this->failPublishWith;
        }

        $id = (string) ++$this->sequence . '-0';
        $this->entries[$stream][] = new StreamMessage($stream, $id, $fields);

        return $id;
    }

    public function ensureGroup(string $stream, string $group): void
    {
        $this->entries[$stream] ??= [];
    }

    public function read(array $streams, string $group, string $consumer, int $count, int $blockMs): array
    {
        $read = [];
        foreach ($streams as $stream) {
            foreach ($this->entries[$stream] ?? [] as $index => $message) {
                if (count($read) >= $count) {
                    break 2;
                }
                $read[] = $message;
                $this->pending[$stream][] = $message;
                unset($this->entries[$stream][$index]);
            }
            $this->entries[$stream] = array_values($this->entries[$stream] ?? []);
        }

        return $read;
    }

    public function claimAbandoned(string $stream, string $group, string $consumer, int $minIdleMs, int $count): array
    {
        $claimed = array_slice($this->pending[$stream] ?? [], 0, $count);

        $this->pending[$stream] = array_map(
            fn (StreamMessage $m) => in_array($m, $claimed, true)
                ? new StreamMessage($m->stream, $m->id, $m->fields, $m->deliveries + 1)
                : $m,
            $this->pending[$stream] ?? [],
        );

        return array_slice($this->pending[$stream], 0, count($claimed));
    }

    public function ack(StreamMessage $message, string $group): void
    {
        $this->pending[$message->stream] = array_values(array_filter(
            $this->pending[$message->stream] ?? [],
            fn (StreamMessage $m) => $m->id !== $message->id,
        ));
    }

    public function deadLetter(StreamMessage $message, string $group, string $reason): void
    {
        $this->deadLettered[] = ['message' => $message, 'reason' => $reason];
        $this->ack($message, $group);
    }

    public function trim(string $stream, int $olderThanMs): void {}

    /** @return list<array<string, mixed>> Payloads publicados en un stream. */
    public function payloads(string $stream): array
    {
        return array_map(
            fn (StreamMessage $m) => json_decode($m->payload(), true),
            $this->entries[$stream] ?? [],
        );
    }
}
