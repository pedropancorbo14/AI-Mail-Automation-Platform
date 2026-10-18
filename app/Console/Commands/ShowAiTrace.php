<?php

namespace App\Console\Commands;

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
 * Muestra lo que hizo la IA con un email (la última ejecución guardada):
 * pasos, herramientas con lo que devolvieron, propuesta, decisión y coste.
 * No llama a la IA ni envía nada. Solo para el operador (incluye costes).
 */
#[Signature('ai:trace {email : Id del email}')]
#[Description('Muestra la traza de la IA para un email: pasos, herramientas, propuesta y coste')]
class ShowAiTrace extends Command
{
    public function handle(TenantService $tenants): int
    {
        // Consulta global deliberada: el comando no tiene tenant; se entra en el del email.
        $email = Email::withoutGlobalScope(TenantScope::class)->with('tenant')->find($this->argument('email'));
        if ($email === null) {
            $this->error('No existe ese email.');

            return self::FAILURE;
        }

        return $tenants->runAs($email->tenant, function () use ($email) {
            $run = Email::findOrFail($email->id)->agentRuns()->first();
            if ($run === null) {
                $this->warn('Este email no tiene ejecuciones de la IA.');

                return self::FAILURE;
            }
            $this->trace($run);

            return self::SUCCESS;
        });
    }

    private function trace(AiAgentRun $run): void
    {

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
