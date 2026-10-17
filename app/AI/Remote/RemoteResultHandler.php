<?php

namespace App\AI\Remote;

use App\AI\Agent\Enums\AgentRunStatus;
use App\AI\Agent\Enums\AgentStopReason;
use App\AI\Classification\EmailClassification;
use App\AI\Classification\EmailIntent;
use App\AI\CostCalculator;
use App\AI\Data\LLMUsage;
use App\AI\Enums\AiRequestStatus;
use App\AI\Remote\Data\StreamMessage;
use App\AI\Remote\Exceptions\InvalidEventException;
use App\AI\Remote\Exceptions\TenantMismatchException;
use App\Automation\AutoSendPolicy;
use App\Automation\EmailProcessor;
use App\Automation\ReplySender;
use App\Mail\Enums\EmailStatus;
use App\Models\AiAgentRun;
use App\Models\AiRequest;
use App\Models\AiToolCall;
use App\Models\Email;
use App\Tenancy\Scopes\TenantScope;
use App\Tenancy\TenantService;
use App\Tools\Enums\ToolCallStatus;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Aplica un resultado de ai-service (email.processed.v1 o
 * email.processing_failed.v1) a su email. ai-service recomienda; Laravel
 * decide: la respuesta pasa por AutoSendPolicy (modo de la empresa,
 * intenciones permitidas, anti-bucles, revisión del texto) como las del
 * motor de Laravel.
 *
 * Lo que hizo ai-service queda igual que lo que hace el agente de Laravel:
 * una ejecución (ai_agent_runs) con sus peticiones (ai_requests) y sus
 * herramientas (ai_tool_calls, la búsqueda en la documentación incluida).
 * Así la traza del panel funciona igual y ReplyContentGuard compara la
 * respuesta con los datos que la IA consultó de verdad.
 *
 * Seguridad: el email se busca por id y su empresa TIENE que coincidir con
 * el tenant_id del evento; si no, no se toca nada (cola de errores + log
 * crítico). Idempotente: solo se aplica si el email sigue en processing
 * (entregas repetidas y resultados tardíos se descartan).
 */
class RemoteResultHandler
{
    public const PROCESSED = 'email.processed';

    public const FAILED = 'email.processing_failed';

    public function __construct(
        private readonly EventContract $contract,
        private readonly TenantService $tenants,
        private readonly AutoSendPolicy $policy,
        private readonly ReplySender $sender,
        private readonly CostCalculator $costs,
    ) {
    }

    /** @return list<string> Streams de resultados (con el prefijo configurado). */
    public static function streams(): array
    {
        $prefix = config('ai.remote.stream_prefix');

        return ["{$prefix}" . self::PROCESSED . '.v1', "{$prefix}" . self::FAILED . '.v1'];
    }

    /**
     * @return string Resultado: applied, stale, missing.
     *
     * @throws InvalidEventException si no cumple el contrato o la empresa no coincide.
     */
    public function handle(StreamMessage $message): string
    {
        $type = self::eventType($message->stream);
        $event = $this->contract->validate("{$type}.v1", $message->payload());

        $email = Email::withoutGlobalScope(TenantScope::class)->with('tenant')->find($event['email_id']);

        if ($email === null) {
            Log::notice('Resultado de IA para un email que ya no existe', ['email_id' => $event['email_id']]);

            return 'missing';
        }

        if ($email->tenant_id !== $event['tenant_id']) {
            Log::critical('Resultado de IA con una empresa que no es la del email: descartado', [
                'email_id' => $email->id,
                'email_tenant_id' => $email->tenant_id,
                'event_tenant_id' => $event['tenant_id'],
                'event_id' => $event['event_id'],
            ]);

            throw new TenantMismatchException('La empresa del evento no coincide con la del email.');
        }

        Context::add('trace_id', $event['trace_id']);

        return $this->tenants->runAs($email->tenant, fn () => DB::transaction(function () use ($email, $event, $type) {
            // Bloqueo de la fila: dos consumidores con el mismo resultado no lo aplican dos veces.
            $email = Email::query()->lockForUpdate()->findOrFail($email->id);

            if ($email->status !== EmailStatus::Processing) {
                Log::info('Resultado de IA descartado: el email ya no lo esperaba', [
                    'email_id' => $email->id,
                    'status' => $email->status->value,
                ]);

                return 'stale';
            }

            if (($event['causation_id'] ?? null) && $email->ai_event_id && $event['causation_id'] !== $email->ai_event_id) {
                // Respuesta a una petición anterior (republicada): vale igual, el email es el mismo.
                Log::info('Resultado de IA de una petición anterior', ['email_id' => $email->id]);
            }

            $requests = $this->recordRequests($email, $event['usage']['calls'] ?? []);

            $type === self::PROCESSED
                ? $this->applyProcessed($email, $event, $requests)
                : $this->applyFailed($email, $event);

            return 'applied';
        }));
    }

    public static function eventType(string $stream): string
    {
        $name = substr($stream, strlen((string) config('ai.remote.stream_prefix')));

        return match ($name) {
            self::PROCESSED . '.v1' => self::PROCESSED,
            self::FAILED . '.v1' => self::FAILED,
            default => throw new InvalidEventException("Stream desconocido: {$stream}."),
        };
    }

    /**
     * Una fila de ai_requests por llamada al modelo (también las fallidas: se
     * facturan). El coste lo calcula Laravel con sus precios (config/ai.php).
     *
     * @param  list<array<string, mixed>>  $calls
     * @return list<AiRequest>
     */
    private function recordRequests(Email $email, array $calls): array
    {
        return array_map(function (array $call) use ($email) {
            $usage = new LLMUsage($call['input_tokens'], $call['output_tokens'], $call['cache_write_tokens'], $call['cache_read_tokens']);

            return AiRequest::create([
                'email_id' => $email->id,
                'purpose' => $call['purpose'],
                'provider' => $call['provider'],
                'requested_model' => $call['requested_model'],
                'model' => $call['model'],
                'used_fallback' => $call['model'] !== $call['requested_model'],
                'input_tokens' => $usage->inputTokens,
                'output_tokens' => $usage->outputTokens,
                'cache_write_tokens' => $usage->cacheWriteTokens,
                'cache_read_tokens' => $usage->cacheReadTokens,
                'cost_usd' => $this->costs->forUsage($call['model'], $usage),
                'latency_ms' => $call['latency_ms'],
                'status' => AiRequestStatus::from($call['status']),
                'stop_reason' => $call['stop_reason'] ?? null,
                'error' => $call['error'] ?? null,
                'provider_request_id' => $call['provider_request_id'] ?? null,
                'response' => ['source' => 'ai-service', 'prompt' => $call['prompt'] ?? null],
            ]);
        }, $calls);
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  list<AiRequest>  $requests
     */
    private function applyProcessed(Email $email, array $event, array $requests): void
    {
        $c = $event['classification'];
        $classifyRequest = collect($requests)->first(fn (AiRequest $r) => str_ends_with($r->purpose, 'classification') && $r->status === AiRequestStatus::Success);
        $classifyRequest?->forceFill(['intent' => $event['category'], 'confidence' => $c['confidence']])->save();

        $email->recordClassification(new EmailClassification(
            intent: EmailIntent::from($event['category']),
            confidence: (float) $c['confidence'],
            language: (string) ($event['language'] ?? ''),
            summary: $c['summary'],
            needsHuman: $c['needs_human'],
            needsHumanReason: $c['needs_human_reason'],
            sentiment: $c['sentiment'],
            urgency: $c['urgency'],
            injectionSuspected: $c['injection_suspected'],
            orderNumbers: $c['order_numbers'],
            invoiceNumbers: $c['invoice_numbers'],
            customerName: $c['customer_name'],
            aiRequestId: $classifyRequest?->id,
        ));

        if ($reason = EmailProcessor::ignoreReason($email)) {
            $email->transitionTo(EmailStatus::Ignored, $reason);

            return;
        }

        $reasons = implode(' ', $event['reasons']);
        $prefix = $event['decision'] === 'escalate' ? 'La IA lo escala a una persona' : 'La IA pide revisión humana';

        $run = $this->recordAgentRun($email, $event, $requests);

        if (trim($event['draft']) === '') {
            $email->transitionTo(EmailStatus::HumanReview, $reasons !== '' ? "{$prefix}: {$reasons}" : "{$prefix} (sin propuesta de respuesta).");

            return;
        }

        $reply = $email->replies()->create([
            'agent_run_id' => $run?->id,
            'body' => $event['draft'],
            'language' => $event['language'],
            'confidence' => $event['confidence'],
            'needs_human' => $event['decision'] !== 'auto_send',
            'needs_human_reason' => $event['decision'] !== 'auto_send' ? ($reasons !== '' ? $reasons : $prefix . '.') : null,
            'sources' => $event['sources'] ?? [],
            'pending_actions' => [],
            'internal_note' => null,
        ])->refresh();

        $email->transitionTo(EmailStatus::AiReview, sprintf('Respuesta propuesta por ai-service (confianza %.2f).', $event['confidence']));

        // La última palabra es de Laravel, aunque ai-service recomiende auto_send.
        $decision = $this->policy->check($email, $reply);

        if (! $decision->allowed()) {
            $email->transitionTo(EmailStatus::HumanReview, $decision->reason());

            return;
        }

        $this->sender->approve($reply, sprintf(
            'Envío automático: respuesta segura a «%s» (confianza %.2f).',
            $email->intent->value,
            $event['confidence'],
        ));
    }

    /**
     * Ejecución del agente (todo menos la clasificación) con sus herramientas.
     * Sin borrador ni herramientas (spam, manipulación) no hay ejecución.
     *
     * @param  array<string, mixed>  $event
     * @param  list<AiRequest>  $requests
     */
    private function recordAgentRun(Email $email, array $event, array $requests): ?AiAgentRun
    {
        $steps = array_values(array_filter($requests, fn (AiRequest $r) => ! str_ends_with($r->purpose, 'classification')));
        $toolCalls = $event['tool_calls'] ?? [];

        if ($steps === [] && $toolCalls === []) {
            return null;
        }

        $duration = (int) ($event['duration_ms'] ?? 0);
        $run = AiAgentRun::create(['email_id' => $email->id, 'started_at' => now()->subMilliseconds($duration)]);
        $run->forceFill([
            'status' => AgentRunStatus::Completed,
            'stop_reason' => AgentStopReason::EndTurn,
            'iterations' => count(array_filter($steps, fn (AiRequest $r) => str_ends_with($r->purpose, 'draft'))),
            'tool_calls_count' => count($toolCalls),
            'input_tokens' => array_sum(array_map(fn (AiRequest $r) => $r->input_tokens, $steps)),
            'output_tokens' => array_sum(array_map(fn (AiRequest $r) => $r->output_tokens, $steps)),
            'cost_usd' => sprintf('%.6f', array_sum(array_map(fn (AiRequest $r) => (float) $r->cost_usd, $steps))),
            'duration_ms' => $duration,
            'final_output' => [
                'source' => 'ai-service',
                'reply_body' => $event['draft'],
                'confidence' => $event['confidence'],
                'decision' => $event['decision'],
                'reasons' => $event['reasons'],
                'sources' => $event['sources'] ?? [],
            ],
            'finished_at' => now(),
        ])->save();

        foreach ($steps as $step) {
            $step->forceFill(['agent_run_id' => $run->id])->save();
        }

        // Las herramientas cuelgan de un paso de la ejecución (la búsqueda, o el borrador).
        $owner = collect($steps)->first(fn (AiRequest $r) => str_ends_with($r->purpose, 'knowledge_search'))
            ?? collect($steps)->first();

        foreach ($toolCalls as $call) {
            AiToolCall::create([
                'ai_request_id' => $owner?->id,
                'email_id' => $email->id,
                'tool' => $call['tool'],
                'arguments' => $call['arguments'],
                'result' => $call['result'],
                'status' => ToolCallStatus::from($call['status']),
                'error' => $call['error'],
                'duration_ms' => $call['duration_ms'],
            ]);
        }

        return $run;
    }

    /** @param  array<string, mixed>  $event */
    private function applyFailed(Email $email, array $event): void
    {
        $email->transitionTo(
            $event['error_code'] === 'llm_config' ? EmailStatus::Failed : EmailStatus::HumanReview,
            $event['error_code'] === 'llm_config'
                ? "El servicio de IA no está bien configurado: {$event['message']}"
                : "El servicio de IA no pudo procesarlo ({$event['error_code']}): {$event['message']} Lo atiende una persona.",
        );
    }
}
