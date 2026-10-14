<?php

namespace App\AI\Data;

use InvalidArgumentException;

final readonly class LLMRequest
{
    public const array EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    /**
     * Conversación (siempre empieza por el usuario y solo se AÑADE: nunca se
     * editan turnos anteriores, que invalidaría el razonamiento del modelo):
     *  - ['role' => 'user', 'content' => string]
     *  - ['role' => 'assistant', 'content' => mixed]  turno del modelo tal cual
     *    (LLMResponse::$assistantContent, opaco: incluye su razonamiento)
     *  - ['role' => 'user', 'tool_results' => list<LLMToolResult>]
     *
     * @param  string  $system  Instrucciones del sistema (las de la plataforma, nunca texto del cliente).
     * @param  list<array<string, mixed>>  $messages
     * @param  array<string, mixed>|null  $jsonSchema  Esquema JSON de la respuesta (structured outputs).
     * @param  string|null  $effort  Profundidad del razonamiento (self::EFFORTS); null = la del modelo.
     * @param  string|null  $model  Modelo concreto; null = el configurado.
     * @param  list<array{name: string, description: string, input_schema: array<string, mixed>}>  $tools
     *         Herramientas que puede pedir (ToolRegistry::definitions()).
     */
    public function __construct(
        public string $system,
        public array $messages,
        public int $maxTokens,
        public ?array $jsonSchema = null,
        public ?string $effort = null,
        public ?string $model = null,
        public array $tools = [],
    ) {
        if ($messages === [] || $messages[0]['role'] !== 'user') {
            throw new InvalidArgumentException('La conversación debe empezar con un mensaje del usuario.');
        }

        if ($effort !== null && ! in_array($effort, self::EFFORTS, true)) {
            throw new InvalidArgumentException("Esfuerzo no válido: {$effort}.");
        }

        if ($maxTokens < 1) {
            throw new InvalidArgumentException('maxTokens debe ser positivo.');
        }
    }

    /** Petición de un solo mensaje del usuario. */
    public static function single(string $system, string $user, int $maxTokens, ?array $jsonSchema = null, ?string $effort = null): self
    {
        return new self($system, [['role' => 'user', 'content' => $user]], $maxTokens, $jsonSchema, $effort);
    }
}
