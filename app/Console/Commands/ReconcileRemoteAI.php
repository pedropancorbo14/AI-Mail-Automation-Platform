<?php

namespace App\Console\Commands;

use App\AI\Remote\AIEngine;
use App\AI\Remote\EmailEventPublisher;
use App\AI\Remote\Exceptions\InvalidEventException;
use App\Automation\ProcessEmailJob;
use App\Mail\Enums\EmailStatus;
use App\Models\Email;
use App\Tenancy\Scopes\TenantScope;
use App\Tenancy\TenantService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Emails enviados a ai-service que llevan demasiado en processing sin
 * resultado (servicio caído, Redis caído al publicar, evento perdido):
 *
 *  - Con AI_ENGINE=python se vuelven a publicar (evento nuevo) hasta
 *    ai.remote.max_publish_attempts; agotados → failed.
 *  - Si se volvió a AI_ENGINE=laravel, los procesa el motor de Laravel.
 */
class ReconcileRemoteAI extends Command
{
    protected $signature = 'ai:reconcile-remote';

    protected $description = 'Vuelve a publicar (o da por fallidos) los emails sin respuesta del servicio de IA';

    public function handle(EmailEventPublisher $publisher, TenantService $tenants): int
    {
        $limit = now()->subMinutes((int) config('ai.remote.reconcile_after_minutes'));
        $maxAttempts = (int) config('ai.remote.max_publish_attempts');
        $remote = AIEngine::current()->isRemote();
        $counts = ['republished' => 0, 'failed' => 0, 'local' => 0];

        // Consulta global deliberada: el comando revisa todas las empresas.
        Email::withoutGlobalScope(TenantScope::class)
            ->with('tenant')
            ->where('status', EmailStatus::Processing)
            ->whereNotNull('ai_requested_at')
            ->where('ai_requested_at', '<', $limit)
            ->chunkById(100, function ($emails) use ($publisher, $tenants, $remote, $maxAttempts, &$counts) {
                foreach ($emails as $email) {
                    $tenants->runAs($email->tenant, function () use ($email, $publisher, $remote, $maxAttempts, &$counts) {
                        if (! $remote) {
                            ProcessEmailJob::dispatch($email->tenant_id, $email->id);
                            $counts['local']++;

                            return;
                        }

                        if ($email->ai_request_attempts >= $maxAttempts) {
                            $email->transitionTo(EmailStatus::Failed, "El servicio de IA no respondió tras {$email->ai_request_attempts} intentos.");
                            $counts['failed']++;

                            return;
                        }

                        try {
                            $publisher->publish($email);
                            $counts['republished']++;
                        } catch (InvalidEventException $e) {
                            $email->transitionTo(EmailStatus::HumanReview, "No se pudo enviar al servicio de IA ({$e->getMessage()}): lo atiende una persona.");
                        }
                    });
                }
            });

        if (array_sum($counts) > 0) {
            Log::warning('Emails sin respuesta del servicio de IA', $counts);
        }

        $this->info(sprintf(
            'Republicados: %d · Fallidos: %d · Al motor de Laravel: %d',
            $counts['republished'],
            $counts['failed'],
            $counts['local'],
        ));

        return self::SUCCESS;
    }
}
