<?php

namespace App\Console\Commands;

use App\Billing\PlanCatalog;
use App\Billing\PlanLimit;
use App\Billing\SubscriptionStatus;
use App\Models\Tenant;
use App\Security\Audit\AuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Plan y suscripción de una empresa a mano (Fase 17): para el plan a medida,
 * para quien paga por transferencia o para corregir algo. Si la empresa paga
 * con Stripe, el siguiente aviso de Stripe vuelve a mandar.
 */
#[Signature('tenant:plan
    {tenant : Id o slug de la empresa}
    {--plan= : Plan nuevo}
    {--status= : Estado: trialing, active, past_due o canceled}
    {--trial-days= : Con --status=trialing, días de prueba desde hoy}')]
#[Description('Muestra y cambia el plan y la suscripción de una empresa')]
class ManageTenantPlan extends Command
{
    public function handle(AuditLogger $audit): int
    {
        $value = (string) $this->argument('tenant');
        $tenant = ctype_digit($value) ? Tenant::find($value) : Tenant::where('slug', $value)->first();

        if ($tenant === null) {
            $this->error('No existe esa empresa.');

            return self::FAILURE;
        }

        if ($this->option('plan') !== null || $this->option('status') !== null || $this->option('trial-days') !== null) {
            $plan = PlanCatalog::find($this->option('plan') ?? $tenant->plan);
            $status = SubscriptionStatus::tryFrom($this->option('status') ?? $tenant->subscription_status->value);

            if ($plan === null) {
                $this->error('El plan no existe. Planes: '.implode(', ', PlanCatalog::keys()).'.');

                return self::FAILURE;
            }

            if ($status === null) {
                $this->error('Estado no válido. Estados: '.implode(', ', array_column(SubscriptionStatus::cases(), 'value')).'.');

                return self::FAILURE;
            }

            $before = ['plan' => $tenant->plan, 'subscription_status' => $tenant->subscription_status->value];
            $trialDays = $this->option('trial-days') === null ? null : (int) $this->option('trial-days');
            $tenant->assignPlan($plan, $status, $trialDays);

            $audit->record('tenant.plan_changed', $tenant, $tenant->name, changes: [
                'plan' => ['from' => $before['plan'], 'to' => $plan->key],
                'subscription_status' => ['from' => $before['subscription_status'], 'to' => $status->value],
            ], metadata: ['source' => 'operator'], tenantId: $tenant->id);
        }

        $plan = $tenant->currentPlan();

        $this->table(['Empresa', 'Plan', 'Suscripción', 'Fin de la prueba', '¿IA?'], [[
            $tenant->name,
            $plan->name." ({$plan->key})",
            $tenant->subscription_status->label(),
            $tenant->trial_ends_at?->format('d/m/Y') ?? '—',
            $tenant->subscriptionBlockReason() ?? 'sí',
        ]]);

        $this->table(['Límite', 'Valor'], array_map(
            fn (PlanLimit $limit) => [$limit->label(), $plan->limit($limit) ?? 'sin límite'],
            PlanLimit::cases(),
        ));

        return self::SUCCESS;
    }
}
