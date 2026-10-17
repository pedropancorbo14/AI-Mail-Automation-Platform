<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Copia en modo sombra de un email procesada por ai-service (AI-Service,
 * Fase 5). Lo que recomendó NUNCA se aplica al email: solo se compara con lo
 * que hizo el motor de Laravel. La crea App\AI\Remote\ShadowPublisher y la
 * completa App\AI\Remote\ShadowResultHandler.
 */
class AiShadowRun extends Model
{
    use BelongsToTenant;

    public const PENDING = 'pending';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    // Nada asignable en masa: todo lo escriben el publicador y el consumidor.
    protected $guarded = ['*'];

    protected $attributes = [
        'status' => self::PENDING,
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
            'confidence' => 'decimal:3',
            'reply_confidence' => 'decimal:3',
            'reasons' => 'array',
            'specialists' => 'array',
            'tool_calls' => 'array',
            'cost_usd' => 'decimal:6',
        ];
    }

    /** @return BelongsTo<Email, $this> */
    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }
}
