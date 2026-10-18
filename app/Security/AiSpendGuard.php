<?php

namespace App\Security;

use App\Models\AiRequest;
use App\Models\Email;
use App\Models\Tenant;
use App\Observability\MetricLabels;
use App\Observability\Metrics;

/**
 * Freno del gasto de IA antes de procesar un email. Sin él, quien inunde
 * un buzón con correos (o un bucle con otro sistema) haría pagar una
 * clasificación y un agente por cada uno. Si se supera un límite, el email
 * va a revisión humana sin llamar a la IA:
 *
 *  - Presupuesto diario de la empresa: suma de cost_usd de sus ai_requests
 *    desde las 00:00 (zona horaria de la app). Lo fija la plataforma
 *    (ai.limits.tenant_daily_budget_usd) y el operador puede dar otro a una
 *    empresa (ai:budget). Es un freno, no un tope exacto: un email ya en
 *    marcha termina aunque lo cruce (cada uno tiene su máximo, ai.agent.max_cost_usd).
 *  - Emails de un mismo remitente procesados con IA en 24 h.
 *
 * Se ejecuta dentro del tenant del email.
 */
class AiSpendGuard
{
    public function __construct(
        private readonly Metrics $metrics,
        private readonly MetricLabels $labels,
    ) {
    }

    public function blockReason(Email $email): ?string
    {
        $tenant = $email->tenant;
        $budget = self::dailyBudget($tenant);

        if ($budget !== null && ($spent = self::spentToday()) >= $budget) {
            return $this->block($email, 'budget', sprintf(
                'La empresa ha alcanzado su presupuesto diario de IA (%.2f $ de %.2f $): lo atiende una persona.',
                $spent,
                $budget,
            ));
        }

        $limit = (int) config('ai.limits.max_ai_emails_per_sender_per_day');

        if ($limit > 0 && $email->from_email !== null && $this->recentFromSender($email) >= $limit) {
            return $this->block($email, 'sender', sprintf(
                '%s ha enviado más de %d emails en 24 h: los siguientes los atiende una persona, sin IA.',
                $email->from_email,
                $limit,
            ));
        }

        return null;
    }

    /** Presupuesto diario en USD de la empresa, o null si no tiene límite. */
    public static function dailyBudget(Tenant $tenant): ?float
    {
        $budget = $tenant->ai_daily_budget_usd ?? config('ai.limits.tenant_daily_budget_usd');

        return $budget === null ? null : (float) $budget;
    }

    /** Gasto de IA de hoy de la empresa en contexto. */
    public static function spentToday(): float
    {
        $since = now()->startOfDay();

        return (float) AiRequest::where('created_at', '>=', $since)->sum('cost_usd');
    }

    /**
     * Otros emails de la misma dirección en 24 h que ya pasaron (o están
     * pasando) por la IA: enviados a ai-service o ya clasificados.
     */
    private function recentFromSender(Email $email): int
    {
        return Email::whereRaw('lower(from_email) = ?', [mb_strtolower($email->from_email)])
            ->whereKeyNot($email->getKey())
            ->where('created_at', '>=', now()->subDay())
            ->where(fn ($query) => $query->whereNotNull('ai_requested_at')->orWhereNotNull('classified_at'))
            ->count();
    }

    private function block(Email $email, string $reason, string $message): string
    {
        $this->metrics->increment('ai_guard_blocks_total', ['tenant' => $this->labels->tenant($email->tenant_id), 'reason' => $reason]);

        return $message;
    }
}
