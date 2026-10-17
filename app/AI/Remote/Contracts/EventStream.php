<?php

namespace App\AI\Remote\Contracts;

use App\AI\Remote\Data\StreamMessage;

/**
 * Streams de eventos con consumer groups (Redis Streams). Entrega al menos
 * una vez: lo leído queda pendiente hasta ack(); lo abandonado se reclama.
 */
interface EventStream
{
    /** @param  array<string, string>  $fields  Devuelve el id de la entrada. */
    public function publish(string $stream, array $fields): string;

    public function ensureGroup(string $stream, string $group): void;

    /**
     * @param  list<string>  $streams
     * @return list<StreamMessage>
     */
    public function read(array $streams, string $group, string $consumer, int $count, int $blockMs): array;

    /**
     * Mensajes pendientes de cualquier consumidor que llevan más de $minIdleMs
     * sin confirmar (cada reclamación cuenta como una entrega más).
     *
     * @return list<StreamMessage>
     */
    public function claimAbandoned(string $stream, string $group, string $consumer, int $minIdleMs, int $count): array;

    /** Confirma y borra la entrada (lleva datos personales). */
    public function ack(StreamMessage $message, string $group): void;

    /** Copia a "<stream>.dlq" con el motivo y confirma. */
    public function deadLetter(StreamMessage $message, string $group, string $reason): void;

    /** Borra las entradas más antiguas que $olderThanMs (timestamp en ms). */
    public function trim(string $stream, int $olderThanMs): void;
}
