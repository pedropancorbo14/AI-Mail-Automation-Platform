<?php

namespace App\AI\Exceptions;

use App\AI\Data\LLMResponse;
use Throwable;

/** Límite de peticiones del proveedor (429): reintentable. */
class LLMRateLimitException extends LLMException
{
    public function __construct(string $message, ?LLMResponse $response = null, ?Throwable $previous = null)
    {
        parent::__construct($message, retryable: true, response: $response, previous: $previous);
    }
}
