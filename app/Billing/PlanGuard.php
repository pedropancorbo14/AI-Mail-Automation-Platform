<?php

namespace App\Billing;

use App\Models\Email;
use App\Models\Tenant;
use App\Observability\MetricLabels;
use App\Observability\Metrics;

/**
 * Aplica el plan de cada empresa (Fase 17):
 *
 *  - ensureCanAdd(): antes de añadir un buzón, un usuario o un documento.
 *  - aiBlockReason(): antes de mandar un email a la IA. Con la suscripción
 *    caducada o la cuota del mes agotada el email se guarda igual y va a una
 *    persona: nunca se pierde un correo de un cliente.
 *
 * Los frenos de seguridad (AiSpendGuard: presupuesto diario y remitentes que
 * inundan) se aplican además de estos.
 */
class PlanGuard
{
    public function __construct(
        private readonly UsageService $usage,
        private readonly Metrics $metrics,
        private readonly MetricLabels $labels,
    ) {
    }

    /** @throws PlanLimitException */
    public function ensureCanAdd(Tenant $tenant, PlanLimit $limit): void
    {
        $max = $tenant->currentPlan()->limit($limit);

        if ($max === null) {
            return;
        }

        if ($this->usage->forTenant($tenant)->reached($limit)) {
            throw new PlanLimitException($limit, $tenant->currentPlan(), $max);
        }
    }

    public function aiBlockReason(Email $email): ?string
    {
        $tenant = $email->tenant;

        if ($reason = $tenant->subscriptionBlockReason()) {
            return $this->block($tenant, 'subscription', "{$reason} Lo atiende una persona.");
        }

        $plan = $tenant->currentPlan();

        if ($plan->limit(PlanLimit::AiEmailsPerMonth) === null && $plan->limit(PlanLimit::AiBudgetUsdPerMonth) === null) {
            return null;
        }

        $usage = $this->usage->forTenant($tenant);

        if ($usage->reached(PlanLimit::AiEmailsPerMonth)) {
            return $this->block($tenant, 'plan_emails', sprintf(
                'La empresa ha usado los %d emails con IA de este mes de su plan «%s»: lo atiende una persona.',
                $plan->limit(PlanLimit::AiEmailsPerMonth),
                $plan->name,
            ));
        }

        if ($usage->reached(PlanLimit::AiBudgetUsdPerMonth)) {
            return $this->block($tenant, 'plan_budget', sprintf(
                'La empresa ha alcanzado el gasto de IA de este mes de su plan «%s»: lo atiende una persona.',
                $plan->name,
            ));
        }

        return null;
    }

    private function block(Tenant $tenant, string $reason, string $message): string
    {
        $this->metrics->increment('ai_guard_blocks_total', ['tenant' => $this->labels->tenant($tenant->id), 'reason' => $reason]);

        return $message;
    }
}
