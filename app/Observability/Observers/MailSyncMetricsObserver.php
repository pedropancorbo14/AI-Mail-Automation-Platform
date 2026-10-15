<?php

namespace App\Observability\Observers;

use App\Models\MailSyncState;
use App\Observability\MetricLabels;
use App\Observability\Metrics;

/** Resultado de cada lote de sincronización de un buzón (recordSuccess / recordFailure). */
class MailSyncMetricsObserver
{
    public bool $afterCommit = true;

    public function __construct(private readonly Metrics $metrics, private readonly MetricLabels $labels)
    {
    }

    public function synced(MailSyncState $state): void
    {
        $this->count($state, 'success');
    }

    public function syncFailed(MailSyncState $state): void
    {
        $this->count($state, 'failure');
    }

    private function count(MailSyncState $state, string $result): void
    {
        $this->metrics->increment('mail_syncs_total', [
            'tenant' => $this->labels->tenant($state->tenant_id),
            'provider' => $this->labels->mailProvider($state->mail_connection_id),
            'result' => $result,
        ]);
    }
}
