<?php

namespace App\AI\Data;

final readonly class LLMResponse
{
    /**
     * @param  string  $model  Modelo que respondió de verdad (puede ser el de fallback).
     * @param  array<string, mixed>|null  $data  JSON decodificado si se pidió un esquema.
     * @param  list<array{model: string, usage: LLMUsage}>  $usageByModel  Desglose por modelo
     *                                                                      (fallbacks: cada uno factura lo suyo).
     */
    public function __construct(
        public string $provider,
        public string $model,
        public ?string $text,
        public ?array $data,
        public LLMUsage $usage,
        public ?string $stopReason,
        public int $latencyMs,
        public ?string $requestId = null,
        public bool $usedFallback = false,
        public array $usageByModel = [],
        /** @var list<LLMToolCall> Herramientas que pide el modelo (stopReason "tool_use"). */
        public array $toolCalls = [],
        /** Turno del modelo tal cual, para añadirlo a la conversación sin tocarlo (opaco). */
        public mixed $assistantContent = null,
    ) {
    }

    public function wantsTools(): bool
    {
        return $this->toolCalls !== [];
    }

    /** Copia con los datos JSON ya interpretados. */
    public function withData(array $data): self
    {
        return new self(
            $this->provider, $this->model, $this->text, $data, $this->usage, $this->stopReason, $this->latencyMs,
            $this->requestId, $this->usedFallback, $this->usageByModel, $this->toolCalls, $this->assistantContent,
        );
    }
}
