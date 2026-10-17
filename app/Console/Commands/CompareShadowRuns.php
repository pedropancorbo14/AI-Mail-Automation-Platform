<?php

namespace App\Console\Commands;

use App\AI\Remote\ShadowComparator;
use App\Models\AiShadowRun;
use App\Models\Tenant;
use App\Tenancy\Scopes\TenantScope;
use App\Tenancy\TenantService;
use Illuminate\Console\Command;

/**
 * Compara las copias en modo sombra con lo que hizo el motor de Laravel en
 * cuanto los dos han terminado (programado cada 5 minutos). Una copia sin
 * respuesta de ai-service en 24 h se da por fallida (no_response): cuenta en
 * la comparación como un fallo de ai-service.
 */
class CompareShadowRuns extends Command
{
    protected $signature = 'ai:shadow-compare';

    protected $description = 'Compara las copias en modo sombra de ai-service con el motor de Laravel';

    public function handle(ShadowComparator $comparator, TenantService $tenants): int
    {
        // Consultas globales deliberadas: revisa todas las empresas.
        $expired = AiShadowRun::withoutGlobalScope(TenantScope::class)
            ->where('status', AiShadowRun::PENDING)
            ->where('requested_at', '<', now()->subDay())
            ->update([
                'status' => AiShadowRun::FAILED,
                'error_code' => 'no_response',
                'error' => 'ai-service no respondió en 24 h.',
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

        $compared = 0;
        AiShadowRun::withoutGlobalScope(TenantScope::class)
            ->whereNull('compared_at')
            ->where('status', '!=', AiShadowRun::PENDING)
            ->chunkById(100, function ($runs) use ($comparator, $tenants, &$compared) {
                foreach ($runs as $run) {
                    $tenant = Tenant::find($run->tenant_id);
                    if ($tenant !== null && $tenants->runAs($tenant, fn () => $comparator->compare($run->fresh() ?? $run))) {
                        $compared++;
                    }
                }
            });

        $this->info("Comparadas: {$compared} · Sin respuesta de ai-service: {$expired}");

        return self::SUCCESS;
    }
}
