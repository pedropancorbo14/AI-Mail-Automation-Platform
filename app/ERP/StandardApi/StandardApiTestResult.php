<?php

namespace App\ERP\StandardApi;

final readonly class StandardApiTestResult
{
    /** @param array<string, ?string> $checks recurso => null (correcto) o motivo */
    private function __construct(
        public bool $ok,
        public ?string $error,
        public array $checks,
    ) {
    }

    /** @param list<string> $resources */
    public static function passed(array $resources): self
    {
        return new self(true, null, array_fill_keys($resources, null));
    }

    /** @param array<string, ?string> $checks */
    public static function failed(string $error, array $checks = []): self
    {
        return new self(false, $error, $checks);
    }

    public function toArray(): array
    {
        return ['ok' => $this->ok, 'error' => $this->error, 'checks' => $this->checks];
    }
}
