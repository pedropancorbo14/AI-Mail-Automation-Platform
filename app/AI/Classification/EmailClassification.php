<?php

namespace App\AI\Classification;

/**
 * Clasificación de un email hecha por ai-service (`email.processed.v1`), tal
 * como la guarda `Email::recordClassification()`.
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
}
