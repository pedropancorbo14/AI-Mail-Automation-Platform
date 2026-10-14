<?php

namespace App\AI\Agent;

use App\Models\AiAgentRun;
use App\Models\EmailReply;

/** Resultado de una ejecución del agente: su registro y, si la hubo, la propuesta de respuesta. */
final readonly class AgentResult
{
    public function __construct(
        public AiAgentRun $run,
        public ?EmailReply $reply,
    ) {
    }

    public function hasReply(): bool
    {
        return $this->reply !== null;
    }
}
