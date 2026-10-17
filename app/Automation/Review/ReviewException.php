<?php

namespace App\Automation\Review;

use RuntimeException;

/**
 * Una decisión de revisión que ya no se puede aplicar: el email ya no espera
 * a una persona, la respuesta ya no es un borrador (otra persona o la
 * plataforma se adelantó)... El mensaje es apto para mostrarlo en el panel.
 */
class ReviewException extends RuntimeException
{
    public static function notWaitingForAPerson(): self
    {
        return new self('Este email ya no está esperando a una persona: recarga la página para ver su estado.');
    }

    public static function replyNotDraft(): self
    {
        return new self('Esta respuesta ya no es un borrador: otra persona la ha aprobado o descartado.');
    }

    public static function sendAttempted(): self
    {
        return new self('Ya se intentó enviar una respuesta a este email y puede haber llegado: no se reprocesa con la IA. Responde tú o ciérralo.');
    }

    public static function notReprocessable(): self
    {
        return new self('Este email no se puede reprocesar en su estado actual.');
    }
}
