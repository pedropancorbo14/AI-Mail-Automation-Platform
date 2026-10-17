<?php

namespace App\Observability\Observers;

use App\Models\EmailReview;
use App\Observability\MetricLabels;
use App\Observability\Metrics;

/** Decisiones de la revisión humana (Fase 13) y cuánto esperó cada caso hasta ellas. */
class EmailReviewMetricsObserver
{
    public bool $afterCommit = true;

    public function __construct(private readonly Metrics $metrics, private readonly MetricLabels $labels)
    {
    }

    public function created(EmailReview $review): void
    {
        $tenant = $this->labels->tenant($review->tenant_id);

        $this->metrics->increment('email_reviews_total', [
            'tenant' => $tenant,
            'decision' => $review->decision->value,
            'edited' => (bool) $review->edited,
        ]);

        if ($review->waited_seconds !== null) {
            $this->metrics->observe('email_review_wait_seconds', $review->waited_seconds, [
                'tenant' => $tenant,
                'decision' => $review->decision->value,
            ]);
        }
    }
}
