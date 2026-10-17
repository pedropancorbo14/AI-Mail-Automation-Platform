<?php

namespace App\Models;

use App\AI\Agent\Enums\EmailReplyStatus;
use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Respuesta a un email: la propone el agente (created_by null) o la escribe
 * una persona en la revisión humana (Fase 13). status y los datos de
 * edición, aprobación, descarte y envío no son asignables en masa: los
 * cambian App\Automation\ReplySender y App\Automation\Review\HumanReviewService.
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
            'edited_at' => 'datetime',
            'rejected_at' => 'datetime',
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

    /** @return BelongsTo<User, $this> Quién la escribió (null = la IA). */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> Quién cambió el texto propuesto por la IA. */
    public function editedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    /** @return BelongsTo<User, $this> Quién la descartó. */
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /** Una persona cambió el texto que propuso la IA. */
    public function wasEdited(): bool
    {
        return $this->original_body !== null;
    }

    /** @return BelongsTo<AiAgentRun, $this> */
    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AiAgentRun::class, 'agent_run_id');
    }
}
