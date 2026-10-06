<?php

namespace App\AI\Exceptions;

use App\AI\Data\LLMResponse;
use Throwable;

/** Respuesta inutilizable: JSON inválido o cortada por max_tokens. */
class LLMInvalidResponseException extends LLMException
{
    public function __construct(string $message, ?LLMResponse $response = null, ?Throwable $previous = null)
    {
        parent::__construct($message, retryable: false, response: $response, previous: $previous);
    }
}
