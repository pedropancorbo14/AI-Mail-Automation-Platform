<?php

namespace App\Tools;

use App\Models\Tenant;
use App\Models\TenantTool;
use App\Tenancy\TenantService;
use App\Tools\Contracts\AITool;
use App\Tools\Contracts\HasAvailability;
use InvalidArgumentException;

/**
 * Qué herramientas puede usar la IA en cada empresa: las del catálogo
 * (ToolRegistry) que estén activadas para ella (tabla tenant_tools; sin
 * fila, config ai.tools.default_enabled) y disponibles (si dependen de una
 * fuente de datos, que la empresa la tenga configurada).
 */
class TenantToolService
{
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly TenantService $tenants,
    ) {
    }

    /** @return list<AITool> Herramientas que se ofrecen a la IA en esta empresa (orden estable). */
    public function toolsFor(Tenant $tenant): array
    {
        return $this->tenants->runAs($tenant, function () {
            $settings = TenantTool::pluck('enabled', 'tool')->all();

            $tools = array_values(array_filter(
                $this->registry->all(),
                fn (AITool $tool) => $this->enabled($tool, $settings) && $this->available($tool),
            ));
            usort($tools, fn (AITool $a, AITool $b) => strcmp($a->name(), $b->name()));

            return $tools;
        });
    }

    public function isUsable(Tenant $tenant, string $name): bool
    {
        foreach ($this->toolsFor($tenant) as $tool) {
            if ($tool->name() === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Estado de cada herramienta del catálogo para la empresa (para el comando y el panel).
     *
     * @return list<array{tool: string, enabled: bool, available: bool, source: string}>
     */
    public function statusFor(Tenant $tenant): array
    {
        return $this->tenants->runAs($tenant, function () {
            $settings = TenantTool::pluck('enabled', 'tool')->all();

            return array_map(fn (AITool $tool) => [
                'tool' => $tool->name(),
                'enabled' => $this->enabled($tool, $settings),
                'available' => $this->available($tool),
                'source' => array_key_exists($tool->name(), $settings) ? 'empresa' : 'por defecto',
            ], $this->registry->all());
        });
    }

    public function enable(Tenant $tenant, string $name): void
    {
        $this->set($tenant, $name, true);
    }

    public function disable(Tenant $tenant, string $name): void
    {
        $this->set($tenant, $name, false);
    }

    private function set(Tenant $tenant, string $name, bool $enabled): void
    {
        if (! $this->registry->has($name)) {
            throw new InvalidArgumentException("La herramienta {$name} no existe.");
        }

        $this->tenants->runAs($tenant, fn () => TenantTool::updateOrCreate(['tool' => $name], ['enabled' => $enabled]));
    }

    /** @param  array<string, bool>  $settings */
    private function enabled(AITool $tool, array $settings): bool
    {
        return $settings[$tool->name()] ?? in_array($tool->name(), config('ai.tools.default_enabled', []), true);
    }

    private function available(AITool $tool): bool
    {
        return ! $tool instanceof HasAvailability || $tool->isAvailable();
    }
}
