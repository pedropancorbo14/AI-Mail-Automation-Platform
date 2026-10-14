<?php

namespace App\AI\Agent;

use App\AI\AIService;
use App\AI\Agent\Enums\AgentRunStatus;
use App\AI\Agent\Enums\AgentStopReason;
use App\AI\Data\LLMToolCall;
use App\AI\Data\LLMToolResult;
use App\AI\Exceptions\LLMException;
use App\AI\Exceptions\LLMRefusalException;
use App\AI\Prompts\PromptRegistry;
use App\Models\AiAgentRun;
use App\Models\AiRequest;
use App\Models\Email;
use App\Models\EmailReply;
use App\Tenancy\TenantService;
use App\Tools\Contracts\AITool;
use App\Tools\TenantToolService;
use App\Tools\ToolContext;
use App\Tools\ToolExecutor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * Agente que prepara la respuesta a un email: Claude pide herramientas
 * (pedidos, documentación...), el backend las ejecuta vía ToolExecutor y le
 * devuelve los resultados, hasta que da su respuesta final estructurada.
 *
 * - Conversación SOLO-AÑADIR: system y herramientas fijos durante toda la
 *   ejecución y el turno del modelo devuelto tal cual (Opus 5.5 rechaza
 *   historiales editados).
 * - Límites duros: pasos, llamadas a herramientas, tiempo y gasto. Al
 *   superarlos no hay propuesta (la ejecución queda failed con el motivo).
 * - Todo queda auditado: ai_agent_runs + un ai_request por paso + ai_tool_calls.
 *
 * Debe ejecutarse dentro del tenant del email. No envía nada: deja la
 * propuesta en email_replies.
 */
class AgentService
{
    public function __construct(
        private readonly AIService $ai,
        private readonly EmailAgentPrompt $prompt,
        private readonly EmailAgentContext $context,
        private readonly PromptRegistry $prompts,
        private readonly TenantToolService $tenantTools,
        private readonly ToolExecutor $executor,
        private readonly TenantService $tenants,
        private readonly AgentDecision $decision,
    ) {
    }

    /**
     * @throws LLMException reintentable (429, 5xx, red): la ejecución queda registrada
     *                      como fallida y el job puede reintentar con una nueva.
     */
    public function run(Email $email): AgentResult
    {
        if ($this->tenants->id() !== $email->tenant_id) {
            throw new LogicException('El agente debe ejecutarse dentro del tenant del email.');
        }

        $startedAt = hrtime(true);
        $tools = $this->tenantTools->toolsFor($email->tenant);
        $definitions = array_map(fn (AITool $tool) => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'input_schema' => $tool->inputSchema(),
        ], $tools);

        $run = AiAgentRun::create([
            'email_id' => $email->id,
            'ai_prompt_version_id' => $this->prompts->resolve($this->prompt)->id,
            'started_at' => now(),
        ]);

        [$userContent] = $this->context->build($email);
        $messages = [['role' => 'user', 'content' => $userContent]];
        $toolCalls = 0;
        $iteration = 1;

        try {
            for (; ; $iteration++) {
                if (($limit = $this->limitReached($run, $iteration, $toolCalls, $startedAt)) !== null) {
                    return $this->finish($run, $startedAt, $iteration - 1, $toolCalls, AgentStopReason::from($limit), error: AgentStopReason::from($limit)->label());
                }

                $result = $this->ai->converse(
                    $this->prompt, $messages,
                    maxTokens: (int) config('ai.agent.max_tokens'),
                    effort: config('ai.agent.effort'),
                    tools: $definitions,
                    email: $email,
                    agentRunId: $run->id,
                );

                $response = $result->response;

                if (! $response->wantsTools()) {
                    $reply = AgentReply::fromArray($response->data ?? []);

                    return $this->finish($run, $startedAt, $iteration, $toolCalls, AgentStopReason::EndTurn, reply: $reply, email: $email, output: $response->data);
                }

                if ($toolCalls + count($response->toolCalls) > (int) config('ai.agent.max_tool_calls')) {
                    return $this->finish($run, $startedAt, $iteration, $toolCalls, AgentStopReason::MaxToolCalls, error: AgentStopReason::MaxToolCalls->label());
                }

                // Turno del modelo TAL CUAL + todos los resultados en un solo mensaje.
                $messages[] = ['role' => 'assistant', 'content' => $response->assistantContent];
                $messages[] = ['role' => 'user', 'tool_results' => array_map(
                    fn (LLMToolCall $call) => $this->executeTool($call, $email, $result->record),
                    $response->toolCalls,
                )];
                $toolCalls += count($response->toolCalls);
            }
        } catch (LLMRefusalException $e) {
            return $this->finish($run, $startedAt, $iteration, $toolCalls, AgentStopReason::Refusal, error: $e->getMessage());
        } catch (LLMException $e) {
            $this->finish($run, $startedAt, $iteration, $toolCalls, AgentStopReason::Error, error: $e->getMessage());

            if ($e->retryable) {
                throw $e;
            }

            return new AgentResult($run->refresh(), null);
        } catch (Throwable $e) {
            Log::error('Error inesperado en el agente', ['agent_run_id' => $run->id, 'detail' => $e->getMessage()]);
            $this->finish($run, $startedAt, $iteration, $toolCalls, AgentStopReason::Error, error: 'Error interno del agente.');

            throw $e;
        }
    }

    private function executeTool(LLMToolCall $call, Email $email, AiRequest $step): LLMToolResult
    {
        $result = $this->executor->execute($call->name, $call->input, new ToolContext($email->tenant, $email, $step->id));

        return new LLMToolResult($call->id, (string) json_encode($result->toArray(), JSON_UNESCAPED_UNICODE), ! $result->ok);
    }

    /** Límite que impide dar otro paso (como valor de AgentStopReason), o null. */
    private function limitReached(AiAgentRun $run, int $iteration, int $toolCalls, int $startedAt): ?string
    {
        return match (true) {
            $iteration > (int) config('ai.agent.max_iterations') => AgentStopReason::MaxIterations->value,
            (hrtime(true) - $startedAt) / 1e9 > (int) config('ai.agent.timeout_seconds') => AgentStopReason::Timeout->value,
            $this->spent($run) >= (float) config('ai.agent.max_cost_usd') => AgentStopReason::Budget->value,
            default => null,
        };
    }

    private function spent(AiAgentRun $run): float
    {
        return (float) AiRequest::where('agent_run_id', $run->id)->sum('cost_usd');
    }

    /** @param  array<string, mixed>|null  $output */
    private function finish(
        AiAgentRun $run,
        int $startedAt,
        int $iterations,
        int $toolCalls,
        AgentStopReason $reason,
        ?AgentReply $reply = null,
        ?Email $email = null,
        ?array $output = null,
        ?string $error = null,
    ): AgentResult {
        $steps = AiRequest::where('agent_run_id', $run->id);

        $run->forceFill([
            'status' => $reply ? AgentRunStatus::Completed : AgentRunStatus::Failed,
            'stop_reason' => $reason,
            'error' => $error ? Str::limit($error, 1000) : null,
            'iterations' => $iterations,
            'tool_calls_count' => $toolCalls,
            'input_tokens' => (int) (clone $steps)->sum('input_tokens'),
            'output_tokens' => (int) (clone $steps)->sum('output_tokens'),
            'cost_usd' => number_format((float) (clone $steps)->sum('cost_usd'), 6, '.', ''),
            'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            'final_output' => $output,
            'finished_at' => now(),
        ])->save();

        $draft = null;

        if ($reply !== null && $email !== null) {
            [$needsHuman, $humanReason] = $this->decision->review($reply, $email, $run);

            $draft = $email->replies()->create([
                'agent_run_id' => $run->id,
                'body' => $reply->body,
                'language' => $reply->language ?: null,
                'confidence' => $reply->confidence,
                'needs_human' => $needsHuman,
                'needs_human_reason' => $humanReason,
                'sources' => $reply->sources,
                'pending_actions' => $reply->pendingActions,
                'internal_note' => $reply->internalNote,
            ]);
        }

        Log::log($reply ? 'info' : 'warning', 'Agente de respuesta', [
            'agent_run_id' => $run->id,
            'email_id' => $run->email_id,
            'stop_reason' => $reason->value,
            'iterations' => $iterations,
            'tool_calls' => $toolCalls,
            'cost_usd' => $run->cost_usd,
            'duration_ms' => $run->duration_ms,
            'needs_human' => $draft?->needs_human,
        ]);

        return new AgentResult($run->refresh(), $draft);
    }
}
