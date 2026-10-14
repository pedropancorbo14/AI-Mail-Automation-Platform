<?php

namespace App\Mail\Exceptions;

use App\Mail\Enums\EmailStatus;
use LogicException;

/**
 * Cambio de estado de un email no permitido, o el email ya no estaba en el
 * estado esperado (otro proceso lo cambió antes).
 */
class EmailStatusException extends LogicException
{
    public static function notAllowed(int $emailId, EmailStatus $from, EmailStatus $to): self
    {
        return new self("El email {$emailId} no puede pasar de {$from->value} a {$to->value}.");
    }

    public static function changedMeanwhile(int $emailId, EmailStatus $expected): self
    {
        return new self("El email {$emailId} ya no está en {$expected->value}: otro proceso lo ha cambiado.");
    }
}
