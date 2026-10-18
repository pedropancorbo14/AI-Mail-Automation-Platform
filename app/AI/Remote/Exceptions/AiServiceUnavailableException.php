<?php

namespace App\AI\Remote\Exceptions;

use RuntimeException;

/** La API interna de ai-service no responde o no está configurada (mensaje apto para el usuario). */
class AiServiceUnavailableException extends RuntimeException
{
}
