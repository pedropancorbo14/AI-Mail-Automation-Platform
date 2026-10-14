<?php

namespace App\Console\Commands;

use App\AI\AIService;
use App\AI\Exceptions\LLMException;
use App\Automation\EmailProcessor;
use App\Automation\ProcessEmailJob;
use App\Mail\Enums\EmailStatus;
use App\Models\Email;
use App\Models\EmailStatusChange;
use App\Tenancy\Scopes\TenantScope;
use App\Tenancy\TenantService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Procesa emails a mano (clasificación → agente → envío o revisión
 * humana): uno al momento, o encolar los pendientes / los que fallaron.
 * Consultas globales deliberadas: cada job o ejecución entra en el tenant
 * del email.
 */
#[Signature('ai:process
    {email? : Id del email}
    {--now : Procesarlo al momento y mostrar el resultado}
    {--reprocess : Volver a procesar un email ya decidido (revisión humana, sin respuesta o error)}
    {--pending : Encolar todos los emails recibidos que siguen sin procesar}
    {--failed : Volver a encolar los emails que fallaron al procesarse}')]
#[Description('Procesa emails con la IA: clasificación, propuesta de respuesta y envío o revisión humana')]
class ProcessEmails extends Command
{
    public function handle(EmailProcessor $processor, TenantService $tenants): int
    {
        if ($this->option('pending')) {
            return $this->queueAll(fn (Builder $query) => $query->where('status', EmailStatus::Received)->whereNotNull('ingested_at'), reprocess: false, label: 'pendientes');
        }

        if ($this->option('failed')) {
            return $this->queueAll(fn (Builder $query) => $query->where('status', EmailStatus::Failed), reprocess: true, label: 'con error');
        }

        if ($this->argument('email') === null) {
            $this->error('Indica un email (ai:process 12) o usa --pending o --failed.');

            return self::FAILURE;
        }

        $email = Email::withoutGlobalScope(TenantScope::class)->with('tenant')->find($this->argument('email'));

        if ($email === null) {
            $this->error('No existe ese email.');

            return self::FAILURE;
        }

        $reprocess = (bool) $this->option('reprocess');

        if (! $this->option('now')) {
            ProcessEmailJob::dispatch($email->tenant_id, $email->id, $reprocess);
            $this->info("Procesamiento del email {$email->id} encolado (cola ai).");

            return self::SUCCESS;
        }

        if (! AIService::enabled()) {
            $this->warn('La IA no está configurada (ANTHROPIC_API_KEY): el email irá a revisión humana.');
        }

        return $tenants->runAs($email->tenant, function () use ($processor, $email, $reprocess) {
            $email = Email::findOrFail($email->id);
            $this->line("Procesando «{$email->subject}» de {$email->from_email} (estado: {$email->status->label()})...");

            $status = null;
            $unavailable = false;

            try {
                $status = $processor->process($email, $reprocess);
            } catch (LLMException $e) {
                $this->error("La IA no está disponible ahora: {$e->getMessage()} El email sigue en «Procesando»: vuelve a intentarlo.");
                $unavailable = true;
            }

            if ($status === null && ! $unavailable) {
                $this->warn(match (true) {
                    $reprocess && EmailProcessor::sendWasAttempted($email) => 'Ya se intentó enviar una respuesta a este email: no se reprocesa para no enviarla dos veces. Lo resuelve una persona.',
                    $reprocess => "No se puede reprocesar un email en «{$email->status->label()}».",
                    default => "El email ya está en «{$email->status->label()}»: usa --reprocess para volver a procesarlo.",
                });
            }

            $this->history($email->fresh());

            return $status === null ? self::FAILURE : self::SUCCESS;
        });
    }

    private function history(Email $email): void
    {
        $this->newLine();
        $this->info("Estado actual: {$email->status->label()} ({$email->status->value})");
        $this->table(['Cuándo', 'De', 'A', 'Motivo'], $email->statusChanges()->get()->map(fn (EmailStatusChange $change) => [
            $change->created_at->format('Y-m-d H:i:s'),
            $change->from_status?->label() ?? '-',
            $change->to_status->label(),
            wordwrap((string) $change->reason, 80, "\n", true),
        ])->all());

        if ($email->status === EmailStatus::Approved) {
            $this->line('  La respuesta está aprobada y su envío encolado (cola mail).');
        }

        if ($email->agentRuns()->exists()) {
            $this->line("  Traza del agente: php artisan ai:reply {$email->id} --last");
        }
    }

    /** @param  \Closure(Builder<Email>): Builder<Email>  $filter */
    private function queueAll(\Closure $filter, bool $reprocess, string $label): int
    {
        $count = 0;

        $filter(Email::withoutGlobalScope(TenantScope::class))->chunkById(200, function ($emails) use (&$count, $reprocess) {
            foreach ($emails as $email) {
                ProcessEmailJob::dispatch($email->tenant_id, $email->id, $reprocess);
                $count++;
            }
        });

        $this->info("{$count} emails {$label} encolados para procesar.");

        return self::SUCCESS;
    }
}
