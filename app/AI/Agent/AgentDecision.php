<?php

namespace App\AI\Agent;

use App\Models\AiAgentRun;
use App\Models\Email;

/**
 * Decide si la propuesta del agente necesita revisión humana. De momento
 * respeta lo que indica el modelo; las reglas del backend se añaden en la
 * siguiente tarea.
 */
class AgentDecision
{
    /** @return array{0: bool, 1: string|null} [¿necesita persona?, motivo] */
    public function review(AgentReply $reply, Email $email, AiAgentRun $run): array
    {
        return [$reply->needsHuman, $reply->needsHumanReason];
    }
}
