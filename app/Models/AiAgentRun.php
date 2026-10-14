<?php

namespace App\Models;

use App\AI\Agent\Enums\AgentRunStatus;
use App\AI\Agent\Enums\AgentStopReason;
use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Una ejecución del agente sobre un email. La crea y cierra
 * App\AI\Agent\AgentService.
 *
 * @property AgentRunStatus $status
 * @property AgentStopReason|null $stop_reason
 */
#[Fillable(['email_id', 'ai_prompt_version_id', 'started_at'])]
class AiAgentRun extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'status' => 'running',
    ];

    protected function casts(): array
    {
        return [
            'status' => AgentRunStatus::class,
            'stop_reason' => AgentStopReason::class,
            'cost_usd' => 'decimal:6',
            'final_output' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Email, $this> */
    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    /** @return HasMany<AiRequest, $this> Un paso del agente cada una, en orden. */
    public function aiRequests(): HasMany
    {
        return $this->hasMany(AiRequest::class, 'agent_run_id')->orderBy('id');
    }

    /** @return HasOne<EmailReply, $this> */
    public function reply(): HasOne
    {
        return $this->hasOne(EmailReply::class, 'agent_run_id');
    }
}
