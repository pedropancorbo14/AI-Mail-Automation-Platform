<?php

namespace App\Observability\Observers;

use App\Models\Email;
use App\Observability\MetricLabels;
use App\Observability\Metrics;

/** Emails guardados por la ingesta (una vez por email: la deduplicación ya ocurrió). */
class EmailMetricsObserver
{
    /** Solo si la transacción se confirma: un guardado deshecho no cuenta. */
    public bool $afterCommit = true;

    public function __construct(private readonly Metrics $metrics, private readonly MetricLabels $labels)
    {
    }

    public function created(Email $email): void
    {
        $this->metrics->increment('emails_ingested_total', [
            'tenant' => $this->labels->tenant($email->tenant_id),
            'provider' => $this->labels->mailProvider($email->mail_connection_id),
            'automated' => $email->automated_reason !== null,
        ]);
    }
}
