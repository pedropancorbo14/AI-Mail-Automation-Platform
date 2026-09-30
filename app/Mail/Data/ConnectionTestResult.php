<?php

namespace App\Mail\Data;

/**
 * Resultado de probar una conexión. Recepción y envío se prueban por
 * separado porque en IMAP/SMTP son servidores y credenciales distintos.
 * Los mensajes de error son aptos para mostrar al usuario (sin secretos).
 */
final readonly class ConnectionTestResult
{
    public function __construct(
        public bool $incomingOk,
        public bool $outgoingOk,
        public ?string $incomingError = null,
        public ?string $outgoingError = null,
    ) {
    }

    public function ok(): bool
    {
        return $this->incomingOk && $this->outgoingOk;
    }

    /** Primer error encontrado, para guardarlo como último error de la conexión. */
    public function error(): ?string
    {
        return $this->incomingError ?? $this->outgoingError;
    }
}
