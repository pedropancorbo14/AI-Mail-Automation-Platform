<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Security\AiSpendGuard;
use App\Tenancy\TenantService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Presupuesto diario de IA de una empresa (lo decide el operador de la
 * plataforma, no la empresa). Sin --daily solo muestra el gasto de hoy.
 */
#[Signature('ai:budget
    {tenant : Id de la empresa}
    {--daily= : Presupuesto diario en USD; "default" = el de la plataforma}')]
#[Description('Muestra y cambia el presupuesto diario de IA de una empresa')]
class ManageAiBudget extends Command
{
    public function handle(TenantService $tenants): int
    {
        $tenant = Tenant::find($this->argument('tenant'));

        if ($tenant === null) {
            $this->error('No existe esa empresa.');

            return self::FAILURE;
        }

        if (($daily = $this->option('daily')) !== null) {
            if ($daily !== 'default' && (! is_numeric($daily) || (float) $daily < 0)) {
                $this->error('El presupuesto tiene que ser un número de dólares (p. ej. 25) o "default".');

                return self::FAILURE;
            }

            $tenant->forceFill(['ai_daily_budget_usd' => $daily === 'default' ? null : round((float) $daily, 2)])->save();
        }

        $budget = AiSpendGuard::dailyBudget($tenant);
        $spent = $tenants->runAs($tenant, fn () => AiSpendGuard::spentToday());

        $this->table(['Empresa', 'Presupuesto diario', 'Origen', 'Gastado hoy', 'Disponible'], [[
            $tenant->name,
            $budget === null ? 'sin límite' : sprintf('%.2f $', $budget),
            $tenant->ai_daily_budget_usd === null ? 'plataforma' : 'propio de la empresa',
            sprintf('%.4f $', $spent),
            $budget === null ? '—' : sprintf('%.2f $', max(0, $budget - $spent)),
        ]]);

        return self::SUCCESS;
    }
}
