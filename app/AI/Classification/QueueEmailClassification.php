<?php

namespace App\AI\Classification;

use App\AI\AIService;
use App\Mail\Ingestion\Events\EmailIngested;
use Illuminate\Support\Facades\Log;

/**
 * Al terminar la ingesta de un email, encola su clasificación. Sin API key
 * (IA desactivada) o con AI_CLASSIFY_ON_INGEST=false no hace nada: el email
 * queda guardado sin clasificar y se puede clasificar después (ai:classify).
 */
class QueueEmailClassification
{
    public function handle(EmailIngested $event): void
    {
        if (! config('ai.classification.on_ingest')) {
            return;
        }

        if (! AIService::enabled()) {
            Log::notice('IA desactivada (sin ANTHROPIC_API_KEY): email guardado sin clasificar', [
                'email_id' => $event->emailId,
            ]);

            return;
        }

        ClassifyEmailJob::dispatch($event->tenantId, $event->emailId);
    }
}
