<?php

namespace App\Observability\Observers;

use App\Models\MailSkippedMessage;
use App\Observability\MetricLabels;
use App\Observability\Metrics;

/** Correos apartados por la sincronización porque fallan siempre (mensaje "envenenado"). */
class MailSkippedMessageMetricsObserver
{
    public bool $afterCommit = true;

    public function __construct(private readonly Metrics $metrics, private readonly MetricLabels $labels)
    {
    }

    public function created(MailSkippedMessage $message): void
    {
        $this->metrics->increment('mail_messages_skipped_total', [
            'tenant' => $this->labels->tenant($message->tenant_id),
            'provider' => $this->labels->mailProvider($message->mail_connection_id),
        ]);
    }
}
