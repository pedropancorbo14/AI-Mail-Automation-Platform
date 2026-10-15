<?php

namespace App\Observability\Observers;

use App\AI\Agent\Enums\EmailReplyStatus;
use App\Models\EmailReply;
use App\Observability\MetricLabels;
use App\Observability\Metrics;

/** Propuestas del agente y respuestas enviadas (solas o por una persona) o fallidas. */
class EmailReplyMetricsObserver
{
    public bool $afterCommit = true;

    public function __construct(private readonly Metrics $metrics, private readonly MetricLabels $labels)
    {
    }

    public function created(EmailReply $reply): void
    {
        $this->metrics->increment('ai_agent_replies_total', [
            'tenant' => $this->labels->tenant($reply->tenant_id),
            'needs_human' => (bool) $reply->needs_human,
        ]);
    }

    public function updated(EmailReply $reply): void
    {
        if (! $reply->wasChanged('status')) {
            return;
        }

        $tenant = $this->labels->tenant($reply->tenant_id);

        match ($reply->status) {
            EmailReplyStatus::Sent => $this->metrics->increment('email_replies_sent_total', ['tenant' => $tenant, 'automatic' => (bool) $reply->sent_automatically]),
            EmailReplyStatus::Failed => $this->metrics->increment('email_replies_failed_total', ['tenant' => $tenant]),
            default => null,
        };
    }
}
