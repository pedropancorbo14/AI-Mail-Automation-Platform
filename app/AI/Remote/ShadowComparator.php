<?php

namespace App\AI\Remote;

use App\AI\Classification\EmailIntent;
use App\Mail\Enums\EmailStatus;
use App\Models\AiRequest;
use App\Models\AiShadowRun;
use App\Models\Email;
use App\Models\EmailStatusChange;
use App\Observability\MetricLabels;
use App\Observability\Metrics;
use Illuminate\Support\Carbon;

/**
 * Compara una copia en sombra (lo que recomendó ai-service) con lo que hizo
 * el motor de Laravel con el mismo email (AI-Service, Fase 5). Solo cuando los
 * dos han terminado. Se ejecuta dentro del tenant del email.
 *
 * Se compara la RECOMENDACIÓN de cada motor, no lo que pasó después: el modo de
 * la empresa (borrador o automático) y AutoSendPolicy aplican igual a los dos.
 *
 * Decisiones normalizadas:
 *  - Laravel: email ignorado → ignore; con error → failed; si no, la propuesta
 *    de la IA: sin revisión humana (AgentDecision) → auto_send, con ella o sin
 *    propuesta → human_review.
 *  - Python: copia fallida → failed; spam o respuesta automática con confianza
 *    alta → ignore (la misma regla que aplica Laravel al resultado); escalate →
 *    human_review; si no, su decisión.
 */
class ShadowComparator
{
    /** Estados en los que el motor de Laravel ya decidió. */
    private const array DECIDED = [
        EmailStatus::AiReview, EmailStatus::HumanReview, EmailStatus::Approved,
        EmailStatus::Sent, EmailStatus::Ignored, EmailStatus::Failed,
    ];

    public function __construct(
        private readonly Metrics $metrics,
        private readonly MetricLabels $labels,
    ) {
    }

    /** @return bool Si se pudo comparar (los dos motores han terminado). */
    public function compare(AiShadowRun $run): bool
    {
        $email = $run->email;

        if ($email === null || $run->status === AiShadowRun::PENDING || ! in_array($email->status, self::DECIDED, true)) {
            return false;
        }

        $laravel = self::laravelDecision($email);
        $python = self::pythonDecision($run);
        $laravelCost = (float) AiRequest::where('email_id', $email->id)->sum('cost_usd');

        $run->forceFill([
            'compared_at' => now(),
            'laravel_intent' => $email->intent?->value,
            'laravel_decision' => $laravel,
            'python_decision' => $python,
            'intent_match' => $email->intent !== null && $run->category !== null ? $email->intent->value === $run->category : null,
            'decision_match' => $laravel === $python,
            'laravel_cost_usd' => sprintf('%.6f', $laravelCost),
            'laravel_duration_ms' => self::laravelDuration($email),
        ])->save();

        $tenant = $this->labels->tenant($run->tenant_id);
        $this->metrics->increment('ai_shadow_comparisons_total', [
            'tenant' => $tenant,
            'intent_match' => $run->intent_match,
            'decision_match' => $run->decision_match,
        ]);
        $this->metrics->increment('ai_shadow_decisions_total', ['tenant' => $tenant, 'laravel' => $laravel, 'python' => $python]);
        $this->metrics->increment('ai_shadow_cost_usd_total', ['tenant' => $tenant, 'engine' => 'laravel'], $laravelCost);
        $this->metrics->increment('ai_shadow_cost_usd_total', ['tenant' => $tenant, 'engine' => 'python'], (float) $run->cost_usd);

        return true;
    }

    public static function laravelDecision(Email $email): string
    {
        if ($email->status === EmailStatus::Ignored) {
            return 'ignore';
        }

        if ($email->status === EmailStatus::Failed) {
            return 'failed';
        }

        // La primera propuesta de la IA (no las que escriben o corrigen las personas).
        $proposal = $email->replies()->whereNull('created_by')->oldest('id')->first();

        return $proposal !== null && ! $proposal->needs_human ? 'auto_send' : 'human_review';
    }

    public static function pythonDecision(AiShadowRun $run): string
    {
        if ($run->status === AiShadowRun::FAILED) {
            return 'failed';
        }

        $ignorable = in_array($run->category, [EmailIntent::Spam->value, EmailIntent::AutoReply->value], true)
            && (float) $run->confidence >= (float) config('ai.automation.ignore_min_confidence');

        if ($ignorable) {
            return 'ignore';
        }

        return $run->decision === 'auto_send' ? 'auto_send' : 'human_review';
    }

    /** Desde que empezó a procesarlo hasta su primera decisión (null si no se sabe). */
    private static function laravelDuration(Email $email): ?int
    {
        $changes = $email->statusChanges()->reorder('id')->get(['to_status', 'created_at']);
        $started = $changes->first(fn (EmailStatusChange $c) => $c->to_status === EmailStatus::Processing);
        $decided = $changes->first(fn (EmailStatusChange $c) => $c->to_status !== EmailStatus::Processing && $started !== null && $c->created_at >= $started->created_at);

        if ($started === null || $decided === null) {
            return null;
        }

        return (int) Carbon::parse($started->created_at)->diffInMilliseconds(Carbon::parse($decided->created_at));
    }
}
