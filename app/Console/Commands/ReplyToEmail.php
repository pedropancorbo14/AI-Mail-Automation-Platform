<?php

namespace App\Console\Commands;

use App\AI\AIService;
use App\AI\Agent\AgentService;
use App\AI\Exceptions\LLMException;
use App\Models\AiAgentRun;
use App\Models\AiRequest;
use App\Models\AiToolCall;
use App\Models\Email;
use App\Tenancy\Scopes\TenantScope;
use App\Tenancy\TenantService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Ejecuta el agente sobre un email y muestra todo lo que hizo (pasos,
 * herramientas, propuesta, decisión, coste). Con --last solo muestra la
 * última ejecución guardada, sin volver a llamar a la IA. No envía nada.
 */
#[Signature('ai:reply
    {email : Id del email}
    {--last : Mostrar la última ejecución sin volver a llamar a la IA}')]
#[Description('Prepara con el agente una propuesta de respuesta a un email y muestra la traza')]
class ReplyToEmail extends Command
{
    public function handle(AgentService $agent, TenantService $tenants): int
    {
        // Consulta global deliberada: el comando no tiene tenant; se entra en el del email.
        $email = Email::withoutGlobalScope(TenantScope::class)->with('tenant')->find($this->argument('email'));

        if ($email === null) {
            $this->error('No existe ese email.');

            return self::FAILURE;
        }

        return $tenants->runAs($email->tenant, function () use ($agent, $email) {
            $email = Email::findOrFail($email->id);

            if ($this->option('last')) {
                $run = $email->agentRuns()->first();

                if ($run === null) {
                    $this->warn('Este email no tiene ejecuciones del agente.');

                    return self::FAILURE;
                }
            } else {
                if (! AIService::enabled()) {
                    $this->error('La IA no está configurada: añade ANTHROPIC_API_KEY al .env.');

                    return self::FAILURE;
                }

                $this->line("Preparando respuesta para «{$email->subject}» de {$email->from_email}...");

                try {
                    $run = $agent->run($email)->run;
                } catch (LLMException $e) {
                    $this->error('El agente no pudo terminar: ' . $e->getMessage());
                    $run = $email->agentRuns()->first();
                }
            }

            $this->trace($run);

            return $run?->reply ? self::SUCCESS : self::FAILURE;
        });
    }

    private function trace(?AiAgentRun $run): void
    {
        if ($run === null) {
            return;
        }

        $this->newLine();
        $this->info("Pasos del agente (ejecución {$run->id}):");

        foreach ($run->aiRequests as $i => $step) {
            /** @var AiRequest $step */
            $calls = AiToolCall::where('ai_request_id', $step->id)->orderBy('id')->get();
            $this->line(sprintf('  %d. %s · %s tokens · %s ms · %s USD', $i + 1, $step->status->value,
                $step->input_tokens + $step->output_tokens, $step->latency_ms ?? '-', $step->cost_usd ?? '?'));

            foreach ($calls as $call) {
                $this->line(sprintf('     → %s(%s) [%s] %s', $call->tool, json_encode($call->arguments, JSON_UNESCAPED_UNICODE),
                    $call->status->value, Str::limit((string) json_encode($call->result, JSON_UNESCAPED_UNICODE), 110)));
            }

            if ($calls->isEmpty() && $step->error) {
                $this->line('     ' . $step->error);
            }
        }

        $this->newLine();
        $this->table(['Resultado', ''], [
            ['Fin', "{$run->stop_reason?->value} ({$run->stop_reason?->label()})"],
            ['Pasos / herramientas', "{$run->iterations} / {$run->tool_calls_count}"],
            ['Tokens (entrada / salida)', "{$run->input_tokens} / {$run->output_tokens}"],
            ['Coste', "{$run->cost_usd} USD"],
            ['Duración', number_format(($run->duration_ms ?? 0) / 1000, 1) . ' s'],
        ]);

        $reply = $run->reply;

        if ($reply === null) {
            $this->warn('Sin propuesta de respuesta: ' . ($run->error ?? 'motivo desconocido') . ' → la revisará una persona.');

            return;
        }

        $this->info('Propuesta de respuesta:');
        $this->line(preg_replace('/^/m', '  │ ', $reply->body));
        $this->newLine();
        // Textos largos partidos en líneas: la tabla no se sale de la terminal.
        $this->table(['Decisión', ''], array_map(fn (array $row) => [$row[0], wordwrap((string) $row[1], 90, "\n", true)], [
            ['Confianza', $reply->confidence],
            ['¿Necesita persona?', $reply->needs_human ? 'SÍ' : 'no (se podría enviar sola)'],
            ['Motivos', $reply->needs_human_reason ?? '-'],
            ['Fuentes', collect($reply->sources)->map(fn ($s) => "{$s['type']}: {$s['reference']}")->implode("\n") ?: '-'],
            ['Acciones pendientes', collect($reply->pending_actions ?? [])->map(fn ($a) => "- {$a}")->implode("\n") ?: '-'],
            ['Nota interna', $reply->internal_note ?? '-'],
        ]));
    }
}
