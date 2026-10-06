<?php

namespace App\Models;

use App\AI\Classification\EmailClassification;
use App\AI\Classification\EmailIntent;
use App\Mail\Enums\EmailStatus;
use App\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\EmailFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Email recibido en un buzón conectado. Lo crea la ingesta
 * (App\Mail\Ingestion), nunca una petición del cliente. status,
 * ingested_at y la clasificación no son asignables en masa: cambian con
 * métodos explícitos.
 *
 * @property EmailStatus $status
 * @property EmailIntent|null $intent
 * @property list<string>|null $references
 */
#[Fillable([
    'folder', 'provider_message_id', 'message_id', 'in_reply_to', 'references', 'dedup_hash',
    'from_email', 'from_name', 'subject', 'text_body', 'html_body', 'sent_at',
])]
class Email extends Model
{
    /** @use HasFactory<EmailFactory> */
    use BelongsToTenant, HasFactory;

    protected $attributes = [
        'status' => 'received',
    ];

    protected function casts(): array
    {
        return [
            'references' => 'array',
            'sent_at' => 'datetime',
            'status' => EmailStatus::class,
            'ingested_at' => 'datetime',
            'intent' => EmailIntent::class,
            'intent_confidence' => 'decimal:3',
            'needs_human' => 'boolean',
            'injection_suspected' => 'boolean',
            'ai_entities' => 'array',
            'classified_at' => 'datetime',
        ];
    }

    /** Correo y adjuntos guardados: listo para procesar. */
    public function markIngested(): void
    {
        $this->forceFill(['ingested_at' => now()])->save();
    }

    /** Guarda la clasificación de la IA como la actual (no cambia status: eso es la Fase 9). */
    public function recordClassification(EmailClassification $classification): void
    {
        $this->forceFill([
            'intent' => $classification->intent,
            'intent_confidence' => $classification->confidence,
            'language' => $classification->language ?: null,
            'summary' => $classification->summary ?: null,
            'needs_human' => $classification->needsHuman,
            'needs_human_reason' => $classification->needsHumanReason,
            'sentiment' => $classification->sentiment,
            'urgency' => $classification->urgency,
            'injection_suspected' => $classification->injectionSuspected,
            'ai_entities' => [
                'order_numbers' => $classification->orderNumbers,
                'invoice_numbers' => $classification->invoiceNumbers,
                'customer_name' => $classification->customerName,
            ],
            'classification_ai_request_id' => $classification->aiRequestId,
            'classified_at' => now(),
        ])->save();
    }

    /** @return BelongsTo<MailConnection, $this> */
    public function mailConnection(): BelongsTo
    {
        return $this->belongsTo(MailConnection::class);
    }

    /** @return BelongsTo<AiRequest, $this> */
    public function classificationRequest(): BelongsTo
    {
        return $this->belongsTo(AiRequest::class, 'classification_ai_request_id');
    }

    /** @return HasMany<AiRequest, $this> */
    public function aiRequests(): HasMany
    {
        return $this->hasMany(AiRequest::class);
    }

    /** @return HasMany<EmailRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(EmailRecipient::class)->orderBy('type')->orderBy('position');
    }

    /** @return HasMany<EmailAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(EmailAttachment::class)->orderBy('id');
    }
}
