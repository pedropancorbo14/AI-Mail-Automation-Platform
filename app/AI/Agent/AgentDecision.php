<?php

namespace App\AI\Agent;

use App\AI\Agent\Enums\AgentStopReason;
use App\Models\AiAgentRun;
use App\Models\AiToolCall;
use App\Models\Email;
use App\Tools\Enums\ToolCallStatus;
use Illuminate\Support\Str;

/**
 * La IA propone; el backend decide si la propuesta necesita revisión
 * humana. Basta con UNA de estas condiciones (aunque el modelo diga que
 * está seguro), y se guardan todos los motivos:
 *  - el modelo lo pide;
 *  - confianza por debajo de ai.agent.min_confidence;
 *  - sospecha de prompt injection en el email;
 *  - la clasificación previa ya lo marcó para una persona;
 *  - quedan acciones que debe hacer una persona (reembolso, cambio...);
 *  - alguna herramienta falló o fue rechazada durante la ejecución;
 *  - el email se recortó antes de dárselo al agente.
 */
class AgentDecision
{
    public function __construct(private readonly EmailAgentContext $context)
    {
    }

    /** @return array{0: bool, 1: string|null} [¿necesita persona?, motivos] */
    public function review(AgentReply $reply, Email $email, AiAgentRun $run): array
    {
        $minConfidence = (float) config('ai.agent.min_confidence');
        $reasons = [];

        if ($reply->needsHuman) {
            $reasons[] = 'La IA lo pide: ' . rtrim($reply->needsHumanReason ?? 'sin motivo indicado', '. ') . '.';
        }

        if ($reply->confidence < $minConfidence) {
            $reasons[] = sprintf('Confianza baja (%.2f < %.2f).', $reply->confidence, $minConfidence);
        }

        if ($email->injection_suspected) {
            $reasons[] = 'El email parece intentar dar instrucciones a la IA.';
        }

        if ($email->needs_human) {
            $reasons[] = 'La clasificación lo marcó para revisión: ' . rtrim($email->needs_human_reason ?? 'sin motivo', '. ') . '.';
        }

        if ($reply->pendingActions !== []) {
            $reasons[] = 'Requiere acciones de una persona: ' . rtrim(implode('; ', $reply->pendingActions), '. ') . '.';
        }

        $failedTools = AiToolCall::query()
            ->whereIn('ai_request_id', $run->aiRequests()->select('id'))
            ->where('status', '!=', ToolCallStatus::Success)
            ->pluck('tool')
            ->unique()
            ->all();

        if ($failedTools !== []) {
            $reasons[] = 'Alguna consulta no dio resultado (' . implode(', ', $failedTools) . ').';
        }

        [, $truncated] = $this->context->build($email);

        if ($truncated) {
            $reasons[] = 'El email era demasiado largo y la IA no lo leyó entero.';
        }

        return $reasons === []
            ? [false, null]
            : [true, Str::limit(implode(' ', array_unique($reasons)), 1000)];
    }
}
