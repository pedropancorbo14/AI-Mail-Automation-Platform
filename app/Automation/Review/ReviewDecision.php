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

    /** Aprobó la acción que propuso ai-service (p. ej. abrir un ticket): la IA sigue. */
    case ActionApproved = 'action_approved';

    /** Rechazó la acción que propuso ai-service: la IA sigue sin hacerla. */
    case ActionRejected = 'action_rejected';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Aprobada',
            self::Rejected => 'Propuesta descartada',
            self::Replied => 'Respondida por una persona',
            self::Closed => 'Cerrado sin responder',
            self::Reprocessed => 'Reprocesado con la IA',
            self::ActionApproved => 'Acción de la IA aprobada',
            self::ActionRejected => 'Acción de la IA rechazada',
        };
    }

    /** Decisiones que terminan con una respuesta enviada al cliente. */
    public function sendsReply(): bool
    {
        return $this === self::Approved || $this === self::Replied;
    }
}
