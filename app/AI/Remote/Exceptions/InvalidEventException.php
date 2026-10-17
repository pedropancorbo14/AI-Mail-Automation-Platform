<?php

namespace App\AI\Remote\Exceptions;

use RuntimeException;

/** Un evento no cumple el contrato: nunca se reintenta (va a la cola de errores). */
class InvalidEventException extends RuntimeException
{
}
