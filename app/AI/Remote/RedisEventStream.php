<?php

namespace App\AI\Remote;

use App\AI\Remote\Contracts\EventStream;
use App\AI\Remote\Data\StreamMessage;
use Illuminate\Support\Facades\Redis;
use RedisException;

/**
 * Redis Streams con phpredis, en la conexión "streams" (BD propia y SIN el
 * prefijo de claves de Laravel: el servicio Python usa los mismos nombres).
 */
class RedisEventStream implements EventStream
{
    public function __construct(private readonly string $connection = 'streams') {
    }

    public function publish(string $stream, array $fields): string
    {
        // MAXLEN aproximado: tope de seguridad; cada consumidor borra lo que confirma.
        return (string) $this->client()->xAdd($stream, '*', $fields, 100_000, true);
    }

    public function ensureGroup(string $stream, string $group): void
    {
        try {
            // Desde el principio ("0"): lo publicado antes de crear el grupo también se lee.
            $this->client()->xGroup('CREATE', $stream, $group, '0', true);
        } catch (RedisException $e) {
            if (! str_contains($e->getMessage(), 'BUSYGROUP')) {
                throw $e;
            }
        }
        // phpredis devuelve false (sin excepción) si el grupo ya existe.
        $this->client()->clearLastError();
    }

    public function read(array $streams, string $group, string $consumer, int $count, int $blockMs): array
    {
        $response = $this->client()->xReadGroup($group, $consumer, array_fill_keys($streams, '>'), $count, $blockMs);

        $messages = [];
        foreach ($response ?: [] as $stream => $entries) {
            foreach ($entries as $id => $fields) {
                $messages[] = new StreamMessage((string) $stream, (string) $id, $fields);
            }
        }

        return $messages;
    }

    public function claimAbandoned(string $stream, string $group, string $consumer, int $minIdleMs, int $count): array
    {
        $result = $this->client()->xAutoClaim($stream, $group, $consumer, $minIdleMs, '0-0', $count);
        $entries = is_array($result) ? ($result[1] ?? []) : [];

        $messages = [];
        foreach ($entries as $id => $fields) {
            if (! is_array($fields) || $fields === []) {
                continue; // entrada borrada mientras estaba pendiente
            }
            $pending = $this->client()->xPending($stream, $group, (string) $id, (string) $id, 1);
            $deliveries = (int) ($pending[0][3] ?? 1);
            $messages[] = new StreamMessage($stream, (string) $id, $fields, $deliveries);
        }

        return $messages;
    }

    public function ack(StreamMessage $message, string $group): void
    {
        $this->client()->multi()
            ->xAck($message->stream, $group, [$message->id])
            ->xDel($message->stream, [$message->id])
            ->exec();
    }

    public function deadLetter(StreamMessage $message, string $group, string $reason): void
    {
        $this->client()->xAdd("{$message->stream}.dlq", '*', $message->fields + [
            'reason' => mb_substr($reason, 0, 500),
            'original_id' => $message->id,
            'deliveries' => (string) $message->deliveries,
        ], 10_000, true);

        $this->ack($message, $group);
    }

    public function trim(string $stream, int $olderThanMs): void
    {
        // Exacto (no "~"): el aproximado podría dejar datos personales pasado el plazo.
        $this->client()->rawCommand('XTRIM', $stream, 'MINID', "{$olderThanMs}-0");
    }

    private function client(): \Redis
    {
        /** @var \Redis */
        return Redis::connection($this->connection)->client();
    }
}
