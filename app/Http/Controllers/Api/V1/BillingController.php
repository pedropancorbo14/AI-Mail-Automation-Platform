<?php

namespace App\Http\Controllers\Api\V1;

use App\Billing\Plan;
use App\Billing\PlanCatalog;
use App\Billing\PlanLimit;
use App\Billing\Stripe\StripeClient;
use App\Billing\Stripe\StripeException;
use App\Billing\SubscriptionStatus;
use App\Billing\UsageService;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Security\Audit\AuditLogger;
use App\Tenancy\TenantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Plan, suscripción y uso del mes de la empresa del usuario (Fase 17).
 *
 * Los costes en dólares son solo del operador (decisión de la Fase 11): el
 * límite de gasto de IA del plan se enseña como "capacidad de IA" usada en
 * porcentaje, sin importes (ni del gasto ni del tope).
 */
class BillingController extends Controller
{
    public function __construct(
        private readonly TenantService $tenancy,
        private readonly UsageService $usage,
        private readonly AuditLogger $audit,
    ) {
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
            'plans' => array_map(fn (Plan $p) => [
                ...self::publicPlan($p, $limits),
                'current' => $p->key === $plan->key,
                'purchasable' => StripeClient::enabled() && $p->purchasable(),
            ], PlanCatalog::all()),
            'billing' => [
                // Cobro con Stripe configurado en la plataforma.
                'enabled' => StripeClient::enabled(),
                // Ya paga con Stripe: se gestiona en el portal (cambiar de plan, tarjeta, facturas, cancelar).
                'has_subscription' => $tenant->billing_customer_id !== null && $tenant->subscription_status !== SubscriptionStatus::Canceled,
            ],
        ]]);
    }

    /**
     * Página de pago de Stripe para contratar un plan. Solo si aún no paga
     * con Stripe: quien ya paga cambia de plan en el portal (si no, tendría
     * dos suscripciones).
     */
    public function checkout(Request $request, StripeClient $stripe): JsonResponse
    {
        $tenant = $this->tenancy->currentOrFail();
        Gate::authorize(Permission::BILLING_MANAGE);

        $data = $request->validate(['plan' => ['required', 'string', Rule::in(PlanCatalog::keys())]]);
        $plan = PlanCatalog::get($data['plan']);

        if (! StripeClient::enabled() || ! $plan->purchasable()) {
            return response()->json(['message' => 'Este plan no se puede contratar desde el panel: contacta con nosotros.'], 422);
        }

        if ($tenant->billing_customer_id !== null && $tenant->subscription_status !== SubscriptionStatus::Canceled) {
            return response()->json(['message' => 'Ya tienes una suscripción: cambia de plan desde «Gestionar suscripción».'], 409);
        }

        try {
            $url = $stripe->checkoutUrl($tenant, $plan, $request->user()->email, self::returnUrl());
        } catch (StripeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        $this->audit->record('billing.checkout_started', $tenant, $tenant->name, metadata: ['plan' => $plan->key]);

        return response()->json(['url' => $url]);
    }

    /** Portal del cliente de Stripe (cambiar de plan, tarjeta, facturas, cancelar). */
    public function portal(StripeClient $stripe): JsonResponse
    {
        $tenant = $this->tenancy->currentOrFail();
        Gate::authorize(Permission::BILLING_MANAGE);

        if (! StripeClient::enabled() || $tenant->billing_customer_id === null) {
            return response()->json(['message' => 'Tu empresa aún no paga con tarjeta: elige un plan.'], 422);
        }

        try {
            return response()->json(['url' => $stripe->portalUrl($tenant, self::returnUrl())]);
        } catch (StripeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }

    private static function returnUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/plan';
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
