<?php

namespace App\AI\Agent;

use App\AI\Exceptions\LLMInvalidResponseException;

/** Respuesta final del agente, validada (el esquema no expresa límites numéricos). */
final readonly class AgentReply
{
    /**
     * @param  list<array{type: string, reference: string}>  $sources
     * @param  list<string>  $pendingActions
     */
    public function __construct(
        public string $body,
        public string $language,
        public float $confidence,
        public bool $needsHuman,
        public ?string $needsHumanReason,
        public array $sources,
        public array $pendingActions,
        public ?string $internalNote,
    ) {
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $body = trim((string) ($data['reply_body'] ?? ''));

        if ($body === '') {
            throw new LLMInvalidResponseException('El agente no ha devuelto el texto de la respuesta.');
        }

        $sources = array_values(array_filter(
            is_array($data['sources'] ?? null) ? $data['sources'] : [],
            fn ($source) => is_array($source) && in_array($source['type'] ?? null, ['document', 'order'], true) && is_string($source['reference'] ?? null),
        ));

        return new self(
            body: $body,
            language: substr(strtolower((string) ($data['reply_language'] ?? '')), 0, 10),
            confidence: max(0.0, min(1.0, (float) ($data['confidence'] ?? 0))),
            needsHuman: (bool) ($data['needs_human'] ?? true),
            needsHumanReason: is_string($data['needs_human_reason'] ?? null) && $data['needs_human_reason'] !== '' ? $data['needs_human_reason'] : null,
            sources: array_map(fn (array $s) => ['type' => $s['type'], 'reference' => $s['reference']], $sources),
            pendingActions: array_values(array_filter($data['pending_actions'] ?? [], fn ($a) => is_string($a) && trim($a) !== '')),
            internalNote: is_string($data['internal_note'] ?? null) && $data['internal_note'] !== '' ? $data['internal_note'] : null,
        );
    }
}
