<?php

namespace App\Observability\Observers;

use App\Mail\Enums\EmailStatus;
use App\Models\EmailStatusChange;
use App\Observability\MetricLabels;
use App\Observability\Metrics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cada cambio de estado de un email (el historial de la Fase 9) y, cuando la
 * plataforma decide qué hacer con él, cuánto ha tardado desde que se guardó.
 */
class EmailStatusChangeMetricsObserver
{
    /** Estados en los que la plataforma aún no ha decidido. */
    private const array UNDECIDED = [EmailStatus::Received, EmailStatus::Processing, EmailStatus::AiReview];

    /** Decisiones: ignorarlo, pasarlo a una persona, aprobar su envío automático o fallar. */
    private const array DECISIONS = [EmailStatus::Ignored, EmailStatus::HumanReview, EmailStatus::Approved, EmailStatus::Failed];

    public bool $afterCommit = true;

    public function __construct(private readonly Metrics $metrics, private readonly MetricLabels $labels)
    {
    }

    public function created(EmailStatusChange $change): void
    {
        $tenant = $this->labels->tenant($change->tenant_id);

        $this->metrics->increment('email_status_changes_total', [
            'tenant' => $tenant,
            'to' => $change->to_status->value,
            'by' => $change->user_id === null ? 'platform' : 'user',
        ]);

        if ($change->user_id === null
            && in_array($change->from_status, self::UNDECIDED, true)
            && in_array($change->to_status, self::DECISIONS, true)) {
            $this->observeProcessingTime($change, $tenant);
        }
    }

    private function observeProcessingTime(EmailStatusChange $change, string $tenant): void
    {
        $email = DB::table('emails')->where('id', $change->email_id)->first(['ingested_at', 'created_at']);
        $since = $email?->ingested_at ?? $email?->created_at;

        if ($since === null) {
            return;
        }

        $this->metrics->observe('email_processing_seconds', max(0, Carbon::parse($since)->diffInMilliseconds($change->created_at) / 1000), [
            'tenant' => $tenant,
            'outcome' => $change->to_status->value,
        ]);
    }
}
