<?php

namespace App\AI\Data;

/** Resultado de una herramienta que se devuelve al modelo. */
final readonly class LLMToolResult
{
    public function __construct(
        public string $toolCallId,
        /** JSON con lo que devolvió la herramienta (datos o {"error": ...}). */
        public string $content,
        public bool $isError = false,
    ) {
    }
}
