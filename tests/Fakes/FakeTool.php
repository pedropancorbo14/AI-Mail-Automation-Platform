<?php

namespace Tests\Fakes;

use App\Tools\Contracts\AITool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;
use Closure;

/** Herramienta configurable para tests: nombre, esquema y lo que hace al ejecutarse. */
class FakeTool implements AITool
{
    /** @var list<array{arguments: array<string, mixed>, context: ToolContext}> */
    public array $calls = [];

    /**
     * @param  array<string, mixed>|null  $schema
     * @param  (Closure(array<string, mixed>, ToolContext): ToolResult)|null  $handler
     */
    public function __construct(
        private readonly string $name = 'echo',
        private readonly ?array $schema = null,
        private readonly ?Closure $handler = null,
        private readonly string $description = 'Devuelve los argumentos recibidos.',
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function inputSchema(): array
    {
        return $this->schema ?? [
            'type' => 'object',
            'properties' => ['text' => ['type' => 'string']],
            'required' => ['text'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $this->calls[] = ['arguments' => $arguments, 'context' => $context];

        return $this->handler ? ($this->handler)($arguments, $context) : ToolResult::success($arguments);
    }
}
