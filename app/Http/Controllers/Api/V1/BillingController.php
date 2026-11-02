<?php

namespace App\Http\Controllers\Api\V1;

use App\Billing\Plan;
use App\Billing\PlanCatalog;
use App\Billing\PlanLimit;
use App\Billing\UsageService;
use App\Http\Controllers\Controller;
use App\Tenancy\TenantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Plan, suscripción y uso del mes de la empresa del usuario (Fase 17).
 *
 * Los costes en dólares son solo del operador (decisión de la Fase 11): el
 * límite de gasto de IA del plan se enseña como "capacidad de IA" usada en
 * porcentaje, sin importes (ni del gasto ni del tope).
 */
class BillingController extends Controller
{
    public function __construct(private readonly TenantService $tenancy, private readonly UsageService $usage)
    {
    }

    public function show(): JsonResponse
    {
        $tenant = $this->tenancy->currentOrFail();
        Gate::authorize('view', $tenant);

        $usage = $this->usage->forTenant($tenant);
        $plan = $tenant->currentPlan();

        $limits = array_values(array_filter(PlanLimit::cases(), fn (PlanLimit $l) => $l !== PlanLimit::AiBudgetUsdPerMonth));

        return response()->json(['data' => [
            'plan' => self::publicPlan($plan, $limits),
            'subscription' => [
                'status' => $tenant->subscription_status->value,
                'status_label' => $tenant->subscription_status->label(),
                'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
                'current_period_ends_at' => $tenant->current_period_ends_at?->toIso8601String(),
                // Por qué la IA no trabaja ahora mismo (null = trabaja).
                'ai_block_reason' => $tenant->subscriptionBlockReason(),
            ],
            'usage' => [
                'period' => $usage->period,
                'emails_received' => $usage->emailsReceived,
                'limits' => array_intersect_key($usage->toArray(), array_flip(array_column($limits, 'value'))),
                // Fracción usada del límite de gasto de IA del mes (null = sin límite).
                'ai_capacity_ratio' => ($ratio = $usage->ratio(PlanLimit::AiBudgetUsdPerMonth)) === null ? null : round($ratio, 4),
            ],
            'limit_labels' => array_combine(
                array_column($limits, 'value'),
                array_map(fn (PlanLimit $l) => $l->label(), $limits),
            ),
            'plans' => array_map(fn (Plan $p) => [...self::publicPlan($p, $limits), 'current' => $p->key === $plan->key], PlanCatalog::all()),
        ]]);
    }

    /**
     * @param  list<PlanLimit>  $limits
     * @return array<string, mixed>
     */
    private static function publicPlan(Plan $plan, array $limits): array
    {
        $data = $plan->toArray();
        $data['limits'] = array_intersect_key($data['limits'], array_flip(array_column($limits, 'value')));

        return $data;
    }
}
