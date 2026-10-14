<?php

namespace App\Automation;

use App\Mail\Ingestion\Events\EmailIngested;

/**
 * Al terminar la ingesta de un email, encola su procesamiento
 * (clasificación → agente → envío o revisión humana). Con
 * AI_PROCESS_ON_INGEST=false no hace nada: se procesa a mano (ai:process).
 * Sin API key también se encola: el procesador lo manda a revisión humana.
 */
class QueueEmailProcessing
{
    public function handle(EmailIngested $event): void
    {
        if (config('ai.automation.process_on_ingest')) {
            ProcessEmailJob::dispatch($event->tenantId, $event->emailId);
        }
    }
}
