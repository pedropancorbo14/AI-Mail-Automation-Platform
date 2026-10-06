<?php

namespace App\Console\Commands;

use App\AI\AIService;
use App\AI\Classification\ClassifyEmailJob;
use App\Models\AiRequest;
use App\Models\Email;
use App\Tenancy\Scopes\TenantScope;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Clasifica emails a mano: uno concreto (con --now, al momento y mostrando
 * el resultado y su coste) o todos los ingeridos sin clasificar (--pending,
 * p. ej. los que llegaron mientras no había API key).
 */
#[Signature('ai:classify
    {email? : Id del email a clasificar}
    {--pending : Encolar todos los emails ingeridos sin clasificar}
    {--force : Volver a clasificar aunque ya tenga clasificación}
    {--now : Clasificar ya (sin cola) y mostrar el resultado}')]
#[Description('Clasifica emails con la IA')]
class ClassifyEmails extends Command
{
    public function handle(): int
    {
        if (! AIService::enabled()) {
            $this->error('La IA no está configurada: añade ANTHROPIC_API_KEY al .env.');

            return self::FAILURE;
        }

        if ($this->option('pending')) {
            return $this->queuePending();
        }

        if (! $this->argument('email')) {
            $this->error('Indica el id de un email o usa --pending.');

            return self::FAILURE;
        }

        // Consulta global deliberada: el comando no tiene tenant; el job entra en el del email.
        $email = Email::withoutGlobalScope(TenantScope::class)->find($this->argument('email'));

        if ($email === null) {
            $this->error('No existe ese email.');

            return self::FAILURE;
        }

        $job = new ClassifyEmailJob($email->tenant_id, $email->id, (bool) $this->option('force'));

        if (! $this->option('now')) {
            dispatch($job);
            $this->info("Clasificación del email {$email->id} encolada (cola ai).");

            return self::SUCCESS;
        }

        dispatch_sync($job);

        return $this->showResult($email->id);
    }

    private function queuePending(): int
    {
        $queued = 0;

        Email::withoutGlobalScope(TenantScope::class)
            ->whereNotNull('ingested_at')
            ->when(! $this->option('force'), fn ($query) => $query->whereNull('classified_at'))
            ->select('id', 'tenant_id')
            ->chunkById(500, function ($emails) use (&$queued) {
                foreach ($emails as $email) {
                    ClassifyEmailJob::dispatch($email->tenant_id, $email->id, (bool) $this->option('force'));
                    $queued++;
                }
            });

        $this->info("Emails encolados para clasificar: {$queued}");

        return self::SUCCESS;
    }

    private function showResult(int $emailId): int
    {
        $email = Email::withoutGlobalScope(TenantScope::class)->findOrFail($emailId);
        $request = AiRequest::withoutGlobalScope(TenantScope::class)
            ->where('email_id', $emailId)->latest('id')->first();

        if ($request !== null) {
            $this->table(['Petición IA', 'Estado', 'Modelo', 'Tokens (entrada/salida)', 'Coste (USD)', 'Latencia'], [[
                $request->id,
                $request->status->value,
                $request->model ?? $request->requested_model,
                "{$request->input_tokens} / {$request->output_tokens}",
                $request->cost_usd ?? '?',
                $request->latency_ms !== null ? "{$request->latency_ms} ms" : '-',
            ]]);
        }

        if ($email->classified_at === null) {
            $this->error('El email no se ha podido clasificar' . ($request?->error ? ": {$request->error}" : '.'));

            return self::FAILURE;
        }

        $this->table(['Campo', 'Valor'], [
            ['Asunto', $email->subject],
            ['Intención', $email->intent->value],
            ['Confianza', $email->intent_confidence],
            ['Idioma', $email->language],
            ['Resumen', $email->summary],
            ['Necesita persona', $email->needs_human ? "sí: {$email->needs_human_reason}" : 'no'],
            ['Sentimiento / urgencia', "{$email->sentiment} / {$email->urgency}"],
            ['Posible prompt injection', $email->injection_suspected ? 'sí' : 'no'],
            ['Datos extraídos', json_encode($email->ai_entities, JSON_UNESCAPED_UNICODE)],
        ]);

        return self::SUCCESS;
    }
}
