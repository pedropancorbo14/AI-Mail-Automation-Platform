<?php

namespace App\AI\Exceptions;

use App\AI\Data\LLMResponse;
use Throwable;

/** El modelo (y sus fallbacks) rechazaron la petición por seguridad. */
class LLMRefusalException extends LLMException
{
    public function __construct(string $message, ?LLMResponse $response = null, ?Throwable $previous = null)
    {
        parent::__construct($message, retryable: false, response: $response, previous: $previous);
    }
}
