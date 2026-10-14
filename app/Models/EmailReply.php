<?php

namespace App\Models;

use App\AI\Agent\Enums\EmailReplyStatus;
use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Propuesta de respuesta a un email. status no es asignable en masa: lo
 * cambian el flujo de envío y la revisión humana (Fases 9 y 13).
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
        ];
    }

    /** @return BelongsTo<Email, $this> */
    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    /** @return BelongsTo<AiAgentRun, $this> */
    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AiAgentRun::class, 'agent_run_id');
    }
}
