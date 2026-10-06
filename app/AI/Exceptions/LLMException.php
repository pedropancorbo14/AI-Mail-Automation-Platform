<?php

namespace App\AI\Exceptions;

use App\AI\Data\LLMResponse;
use RuntimeException;
use Throwable;

/**
 * Error de un proveedor de IA. El mensaje es apto para logs y para el
 * usuario (nunca incluye la API key). $response existe cuando el proveedor
 * llegó a responder (los tokens se facturan igual y se registran).
 */
class LLMException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        public readonly ?LLMResponse $response = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
