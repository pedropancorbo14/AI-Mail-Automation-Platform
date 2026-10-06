<?php

namespace App\AI\Contracts;

use App\AI\Data\LLMRequest;
use App\AI\Data\LLMResponse;
use App\AI\Exceptions\LLMException;

/**
 * Proveedor de modelos de lenguaje (Anthropic hoy; OpenAI/Gemini podrían
 * añadirse como otra implementación). El resto de la aplicación solo usa
 * esta interfaz y los DTOs de App\AI\Data, nunca clases de un SDK.
 */
interface LLMProvider
{
    /** Nombre corto del proveedor ("anthropic"), para registrar las peticiones. */
    public function name(): string;

    /**
     * Ejecuta la petición. Con $request->jsonSchema la respuesta llega ya
     * validada y decodificada en LLMResponse::$data.
     *
     * @throws LLMException con un mensaje apto para logs/usuario (sin la API key);
     *                      LLMException::$retryable indica si tiene sentido reintentar.
     */
    public function generate(LLMRequest $request): LLMResponse;
}
