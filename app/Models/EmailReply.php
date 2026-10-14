<?php

namespace App\Models;

use App\AI\Agent\Enums\EmailReplyStatus;
use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Propuesta de respuesta a un email. status y los datos de aprobación y
 * envío no son asignables en masa: los cambia App\Automation\ReplySender
 * (y la revisión humana de la Fase 13).
 *
 * @property EmailReplyStatus $status
 */
#[Fillable([
    'agent_run_id', 'body', 'language', 'confidence', 'needs_human', 'needs_human_reason',
    'sources', 'pending_actions', 'internal_note',
])]
class EmailReply extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'status' => EmailReplyStatus::class,
            'confidence' => 'decimal:3',
            'needs_human' => 'boolean',
            'sources' => 'array',
            'pending_actions' => 'array',
            'approved_at' => 'datetime',
            'sent_automatically' => 'boolean',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Email, $this> */
    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    /** @return BelongsTo<User, $this> Quién la aprobó (null = las reglas de envío automático). */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<AiAgentRun, $this> */
    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AiAgentRun::class, 'agent_run_id');
    }
}
