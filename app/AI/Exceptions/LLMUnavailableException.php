<?php

namespace App\AI\Exceptions;

use App\AI\Data\LLMResponse;
use Throwable;

/** Proveedor caído, sobrecargado, timeout o error de red: reintentable. */
class LLMUnavailableException extends LLMException
{
    public function __construct(string $message, ?LLMResponse $response = null, ?Throwable $previous = null)
    {
        parent::__construct($message, retryable: true, response: $response, previous: $previous);
    }
}
