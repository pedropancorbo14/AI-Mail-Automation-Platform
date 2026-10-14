<?php

namespace App\Tools;

use App\Tools\Contracts\AITool;
use InvalidArgumentException;

/**
 * Catálogo de herramientas que la IA puede pedir. Solo existen las
 * registradas (config ai.tools.available); los nombres son únicos y en
 * snake_case, que es lo que admiten los proveedores de LLM.
 */
class ToolRegistry
{
    /** @var array<string, AITool> */
    private array $tools = [];

    /** @param  iterable<AITool>  $tools */
    public function __construct(iterable $tools = [])
    {
        foreach ($tools as $tool) {
            $this->register($tool);
        }
    }

    public function register(AITool $tool): void
    {
        $name = $tool->name();

        if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name)) {
            throw new InvalidArgumentException("Nombre de herramienta no válido: {$name} (snake_case, máx. 64).");
        }

        if (isset($this->tools[$name])) {
            throw new InvalidArgumentException("Herramienta duplicada: {$name}.");
        }

        $schema = $tool->inputSchema();
        if (($schema['type'] ?? null) !== 'object' || ($schema['additionalProperties'] ?? true) !== false) {
            throw new InvalidArgumentException("El esquema de {$name} debe ser un objeto con additionalProperties: false.");
        }

        $this->tools[$name] = $tool;
    }

    public function get(string $name): ?AITool
    {
        return $this->tools[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /** @return list<AITool> */
    public function all(): array
    {
        return array_values($this->tools);
    }

    /**
     * Definiciones para el modelo (nombre, descripción, esquema), en orden
     * estable: así no cambian entre peticiones (bueno para la caché de prompts).
     *
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function definitions(): array
    {
        $tools = $this->tools;
        ksort($tools);

        return array_values(array_map(fn (AITool $tool) => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'input_schema' => $tool->inputSchema(),
        ], $tools));
    }
}
