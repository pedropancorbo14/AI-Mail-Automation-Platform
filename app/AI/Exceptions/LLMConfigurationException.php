<?php

namespace App\AI\Exceptions;

use App\AI\Data\LLMResponse;
use Throwable;

/** Clave ausente o rechazada, modelo inexistente o petición inválida: reintentar no lo arregla. */
class LLMConfigurationException extends LLMException
{
    public function __construct(string $message, ?LLMResponse $response = null, ?Throwable $previous = null)
    {
        parent::__construct($message, retryable: false, response: $response, previous: $previous);
    }
}
