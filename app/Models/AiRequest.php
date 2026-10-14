<?php

namespace App\Models;

use App\AI\Enums\AiRequestStatus;
use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una llamada a la IA (lograda o no). La crea App\AI\AIService; nunca una
 * petición del cliente.
 *
 * @property AiRequestStatus $status
 */
#[Fillable([
    'email_id', 'ai_prompt_version_id', 'purpose', 'provider', 'requested_model', 'model', 'used_fallback',
    'input_tokens', 'output_tokens', 'cache_write_tokens', 'cache_read_tokens', 'cost_usd', 'latency_ms',
    'status', 'stop_reason', 'error', 'provider_request_id', 'intent', 'confidence', 'response',
])]
class AiRequest extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'status' => AiRequestStatus::class,
            'used_fallback' => 'boolean',
            'cost_usd' => 'decimal:6',
            'confidence' => 'decimal:3',
            'response' => 'array',
        ];
    }

    /** @return BelongsTo<Email, $this> */
    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    /** @return BelongsTo<AiPromptVersion, $this> */
    public function promptVersion(): BelongsTo
    {
        return $this->belongsTo(AiPromptVersion::class, 'ai_prompt_version_id');
    }

    /** @return HasMany<AiToolCall, $this> */
    public function toolCalls(): HasMany
    {
        return $this->hasMany(AiToolCall::class);
    }
}
