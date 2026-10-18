<?php

namespace App\Tools;

use App\AI\ToolCatalog;
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
        private readonly ToolCatalog $catalog,
    ) {
    }

    /**
     * Herramientas del catálogo activadas en la empresa (tenant_tools; sin
     * fila, las activadas por defecto en el catálogo), en orden alfabético.
     * Se mandan a ai-service en cada evento: solo usa estas.
     *
     * @return list<string>
     */
    public function enabledNames(Tenant $tenant): array
    {
        $settings = $this->tenants->runAs($tenant, fn () => TenantTool::pluck('enabled', 'tool')->all());
        $defaults = $this->catalog->defaults();
        $names = array_values(array_filter(
            $this->catalog->names(),
            fn (string $name) => $settings[$name] ?? in_array($name, $defaults, true),
        ));
        sort($names);

        return $names;
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

    /**
     * Estado de cada herramienta del catálogo compartido con ai-service (motor
     * remoto): activada en la empresa y, según ai-service, si tiene datos que
     * consultar (null = ai-service no responde).
     *
     * @return list<array{tool: string, enabled: bool, available: bool|null, source: string}>
     */
    public function catalogStatusFor(Tenant $tenant): array
    {
        $settings = $this->tenants->runAs($tenant, fn () => TenantTool::pluck('enabled', 'tool')->all());
        $enabled = $this->enabledNames($tenant);
        $availability = app(\App\AI\Remote\AiServiceClient::class)->toolAvailability($tenant);

        return array_map(fn (string $name) => [
            'tool' => $name,
            'enabled' => in_array($name, $enabled, true),
            'available' => $availability[$name] ?? null,
            'source' => array_key_exists($name, $settings) ? 'empresa' : 'por defecto',
        ], $this->catalog->names());
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
        if (! $this->registry->has($name) && ! $this->catalog->has($name)) {
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
