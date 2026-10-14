<?php

namespace App\AI\Data;

/** Herramienta que el modelo pide ejecutar. */
final readonly class LLMToolCall
{
    /** @param  array<string, mixed>  $input */
    public function __construct(
        /** Id del proveedor (p. ej. "toolu_..."): el resultado debe devolverse con él. */
        public string $id,
        public string $name,
        public array $input,
    ) {
    }
}
