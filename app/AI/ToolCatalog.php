<?php

namespace App\AI;

use RuntimeException;

/**
 * Catálogo único de herramientas de la IA (/contracts/tools/catalog.json), el
 * mismo que usa ai-service. Laravel decide cuáles se activan en cada empresa
 * (tenant_tools) y se las manda a ai-service en el evento; ai-service solo
 * usa esas. Una herramienta que no esté en el catálogo no existe.
 */
class ToolCatalog
{
    /** @var array<string, array{name: string, label: string, description: string, kind: string, specialist: string, default_enabled: bool, requires_customer: bool}>|null */
    private ?array $tools = null;

    public function __construct(private readonly ?string $path = null)
    {
    }

    /** @return array<string, array{name: string, label: string, description: string, kind: string, specialist: string, default_enabled: bool, requires_customer: bool}> por nombre, en el orden del catálogo */
    public function all(): array
    {
        if ($this->tools === null) {
            $path = $this->path ?? base_path('contracts/tools/catalog.json');
            $data = json_decode((string) @file_get_contents($path), true);
            if (! is_array($data) || ! is_array($data['tools'] ?? null)) {
                throw new RuntimeException("No se puede leer el catálogo de herramientas ({$path}).");
            }
            $this->tools = [];
            foreach ($data['tools'] as $tool) {
                $this->tools[$tool['name']] = $tool;
            }
        }

        return $this->tools;
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->all());
    }

    /** @return list<string> Activadas en una empresa que no ha configurado nada. */
    public function defaults(): array
    {
        return array_keys(array_filter($this->all(), fn (array $tool) => $tool['default_enabled']));
    }
}
