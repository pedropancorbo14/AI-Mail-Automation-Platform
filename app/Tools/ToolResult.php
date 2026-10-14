<?php

namespace App\Tools;

/**
 * Resultado de una herramienta tal como lo verá la IA. Un error no es una
 * excepción: el modelo lo lee y decide (pedir otra cosa, escalar a una
 * persona...). Los mensajes de error no deben incluir detalles internos.
 */
final readonly class ToolResult
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    private function __construct(
        public bool $ok,
        public ?array $data = null,
        public ?string $error = null,
    ) {
    }

    /** @param  array<string, mixed>  $data */
    public static function success(array $data): self
    {
        return new self(true, $data);
    }

    public static function error(string $message): self
    {
        return new self(false, error: $message);
    }

    /** @return array<string, mixed> Lo que recibe el modelo. */
    public function toArray(): array
    {
        return $this->ok ? $this->data : ['error' => $this->error];
    }
}
