<?php

namespace App\AI\Remote\Exceptions;

/** El tenant_id del evento no es el de su email: nunca se aplica. */
class TenantMismatchException extends InvalidEventException
{
}
