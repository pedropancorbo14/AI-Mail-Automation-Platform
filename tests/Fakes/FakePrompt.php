<?php

namespace Tests\Fakes;

use App\AI\Prompts\Prompt;

/** Prompt configurable para tests. */
class FakePrompt implements Prompt
{
    public function __construct(
        private readonly int $version = 1,
        private readonly string $system = 'Clasifica el email.',
        private readonly ?array $schema = ['type' => 'object'],
        private readonly string $key = 'test_prompt',
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function system(): string
    {
        return $this->system;
    }

    public function outputSchema(): ?array
    {
        return $this->schema;
    }
}
