<?php

namespace App\AI\Classification;

use App\AI\Exceptions\LLMInvalidResponseException;

/**
 * Resultado validado de la clasificación. Los structured outputs garantizan
 * la forma del JSON; aquí se validan los límites que el esquema no puede
 * expresar (confianza entre 0 y 1) y se aplican reglas de seguridad.
 */
final readonly class EmailClassification
{
    /**
     * @param  list<string>  $orderNumbers
     * @param  list<string>  $invoiceNumbers
     */
    public function __construct(
        public EmailIntent $intent,
        public float $confidence,
        public string $language,
        public string $summary,
        public bool $needsHuman,
        public ?string $needsHumanReason,
        public string $sentiment,
        public string $urgency,
        public bool $injectionSuspected,
        public array $orderNumbers = [],
        public array $invoiceNumbers = [],
        public ?string $customerName = null,
        /** El cuerpo se recortó antes de enviarlo al modelo. */
        public bool $bodyTruncated = false,
        /** Fila de ai_requests que produjo esta clasificación. */
        public ?int $aiRequestId = null,
    ) {
    }

    /** @param  array<string, mixed>  $data  JSON devuelto por el modelo. */
    public static function fromArray(array $data, bool $bodyTruncated = false, ?int $aiRequestId = null): self
    {
        $intent = EmailIntent::tryFrom((string) ($data['intent'] ?? ''))
            ?? throw new LLMInvalidResponseException('La clasificación no trae una intención válida.');

        $injection = (bool) ($data['injection_suspected'] ?? false);
        $needsHuman = (bool) ($data['needs_human'] ?? true);
        $reason = $data['needs_human_reason'] ?? null;

        // Sospecha de prompt injection: siempre a revisión humana, diga lo que diga el modelo.
        if ($injection && ! $needsHuman) {
            $needsHuman = true;
            $reason = 'El email parece intentar dar instrucciones a la IA.';
        }

        return new self(
            intent: $intent,
            confidence: max(0.0, min(1.0, (float) ($data['confidence'] ?? 0))),
            language: substr(strtolower((string) ($data['language'] ?? '')), 0, 10),
            summary: (string) ($data['summary'] ?? ''),
            needsHuman: $needsHuman,
            needsHumanReason: is_string($reason) && $reason !== '' ? $reason : null,
            sentiment: (string) ($data['sentiment'] ?? 'neutral'),
            urgency: (string) ($data['urgency'] ?? 'normal'),
            injectionSuspected: $injection,
            orderNumbers: array_values(array_filter($data['entities']['order_numbers'] ?? [], 'is_string')),
            invoiceNumbers: array_values(array_filter($data['entities']['invoice_numbers'] ?? [], 'is_string')),
            customerName: is_string($data['entities']['customer_name'] ?? null) ? $data['entities']['customer_name'] : null,
            bodyTruncated: $bodyTruncated,
            aiRequestId: $aiRequestId,
        );
    }
}
