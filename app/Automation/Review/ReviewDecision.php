<?php

namespace App\Automation\Review;

/** Lo que decide una persona sobre un email en revisión humana (tabla email_reviews). */
enum ReviewDecision: string
{
    /** Aprobó la respuesta propuesta (tal cual o editada) y se envía. */
    case Approved = 'approved';

    /** Descartó la propuesta de la IA; el email sigue esperando a una persona. */
    case Rejected = 'rejected';

    /** Escribió su propia respuesta y se envía. */
    case Replied = 'replied';

    /** Lo cerró sin responder (no necesita respuesta). */
    case Closed = 'closed';

    /** Lo mandó a procesar otra vez con la IA. */
    case Reprocessed = 'reprocessed';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Aprobada',
            self::Rejected => 'Propuesta descartada',
            self::Replied => 'Respondida por una persona',
            self::Closed => 'Cerrado sin responder',
            self::Reprocessed => 'Reprocesado con la IA',
        };
    }

    /** Decisiones que terminan con una respuesta enviada al cliente. */
    public function sendsReply(): bool
    {
        return $this === self::Approved || $this === self::Replied;
    }
}
