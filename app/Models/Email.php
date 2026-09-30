<?php

namespace App\Models;

use App\Mail\Enums\EmailStatus;
use App\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\EmailFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Email recibido en un buzón conectado. Lo crea la ingesta
 * (App\Mail\Ingestion), nunca una petición del cliente. status e
 * ingested_at no son asignables en masa: cambian con métodos explícitos.
 *
 * @property EmailStatus $status
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
        ];
    }

    /** @return BelongsTo<MailConnection, $this> */
    public function mailConnection(): BelongsTo
    {
        return $this->belongsTo(MailConnection::class);
    }
}
