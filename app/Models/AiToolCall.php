<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use App\Tools\Enums\ToolCallStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una llamada a una herramienta de la IA. La crea App\Tools\ToolExecutor.
 *
 * @property ToolCallStatus $status
 */
#[Fillable(['ai_request_id', 'email_id', 'tool', 'arguments', 'result', 'status', 'error', 'duration_ms'])]
class AiToolCall extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'result' => 'array',
            'status' => ToolCallStatus::class,
            'duration_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<AiRequest, $this> */
    public function aiRequest(): BelongsTo
    {
        return $this->belongsTo(AiRequest::class);
    }

    /** @return BelongsTo<Email, $this> */
    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }
}
