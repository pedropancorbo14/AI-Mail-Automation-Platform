<?php

namespace App\Mail\Ingestion;

/** Resumen de una sincronización de buzón (para logs, métricas y tests). */
final readonly class SyncResult
{
    public function __construct(
        /** Emails nuevos guardados. */
        public int $stored = 0,
        /** Mensajes recibidos que ya estaban guardados (reintentos, resync). */
        public int $duplicates = 0,
        /** Quedaron mensajes por traer al alcanzar el máximo de lotes. */
        public bool $hasMore = false,
        /** Algún cursor dejó de ser válido y se fijó un punto de partida nuevo. */
        public bool $resynced = false,
        /** Mensajes apartados porque fallan siempre (mensaje "envenenado"). */
        public int $skipped = 0,
    ) {
    }

    public function merge(self $other): self
    {
        return new self(
            $this->stored + $other->stored,
            $this->duplicates + $other->duplicates,
            $this->hasMore || $other->hasMore,
            $this->resynced || $other->resynced,
            $this->skipped + $other->skipped,
        );
    }
}
