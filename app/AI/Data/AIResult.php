<?php

namespace App\AI\Data;

use App\Models\AiRequest;

/** Respuesta de la IA junto con su registro en ai_requests. */
final readonly class AIResult
{
    public function __construct(
        public AiRequest $record,
        public LLMResponse $response,
    ) {
    }
}
