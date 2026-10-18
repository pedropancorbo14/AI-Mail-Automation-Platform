<?php

namespace App\Tools;

use App\AI\Remote\AiServiceClient;
use App\AI\ToolCatalog;
use App\Models\Tenant;
use App\Models\TenantTool;
use App\Tenancy\TenantService;
use InvalidArgumentException;

/**
 * Qué herramientas puede usar la IA (ai-service) en cada empresa: las del
 * catálogo compartido (contracts/tools/catalog.json) activadas para ella
 * (tabla tenant_tools; sin fila, las activadas por defecto en el catálogo).
 * Si tienen datos que consultar lo sabe ai-service.
 */
class TenantToolService
{
    public function __construct(
        private readonly TenantService $tenants,
        private readonly ToolCatalog $catalog,
        private readonly AiServiceClient $client,
    ) {
    }

    /**
     * Herramientas activadas en la empresa, en orden alfabético. Se mandan a
     * ai-service en cada evento: solo usa estas.
     *
     * @return list<string>
     */
    public function enabledNames(Tenant $tenant): array
    {
        $settings = $this->settings($tenant);
        $defaults = $this->catalog->defaults();
        $names = array_values(array_filter(
            $this->catalog->names(),
            fn (string $name) => $settings[$name] ?? in_array($name, $defaults, true),
        ));
        sort($names);

        return $names;
    }

    /**
     * Estado de cada herramienta del catálogo para la empresa (comando y
     * panel): activada y, según ai-service, si tiene datos que consultar
     * (null = ai-service no responde).
     *
     * @return list<array{tool: string, enabled: bool, available: bool|null, source: string}>
     */
    public function statusFor(Tenant $tenant): array
    {
        $settings = $this->settings($tenant);
        $enabled = $this->enabledNames($tenant);
        $availability = $this->client->toolAvailability($tenant);

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
        if (! $this->catalog->has($name)) {
            throw new InvalidArgumentException("La herramienta {$name} no existe.");
        }

        $this->tenants->runAs($tenant, fn () => TenantTool::updateOrCreate(['tool' => $name], ['enabled' => $enabled]));
    }

    /** @return array<string, bool> */
    private function settings(Tenant $tenant): array
    {
        return $this->tenants->runAs($tenant, fn () => TenantTool::pluck('enabled', 'tool')->all());
    }
}
