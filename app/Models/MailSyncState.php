<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Cursor y resultado de la sincronización de una carpeta de un buzón.
 * Se actualiza solo con recordSuccess()/recordFailure().
 */
#[Fillable(['folder'])]
class MailSyncState extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'consecutive_failures' => 0,
        'failing_message_attempts' => 0,
    ];

    /** Eventos propios (métricas): cada lote sincronizado o fallido. */
    protected $observables = ['synced', 'syncFailed'];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
            'last_success_at' => 'datetime',
            'resynced_at' => 'datetime',
            'consecutive_failures' => 'integer',
            'failing_message_attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<MailConnection, $this> */
    public function mailConnection(): BelongsTo
    {
        return $this->belongsTo(MailConnection::class);
    }

    /** Lote sincronizado: se avanza el cursor y se limpia el error. */
    public function recordSuccess(string $cursor, bool $resynced = false): void
    {
        $this->cursor = $cursor;
        $this->last_synced_at = $this->last_success_at = now();
        $this->last_error = null;
        $this->consecutive_failures = 0;
        $this->failing_message_id = null;
        $this->failing_message_attempts = 0;

        if ($resynced) {
            $this->resynced_at = now();
        }

        $this->save();
        $this->fireModelEvent('synced', false);
    }

    /**
     * Un correo concreto ha fallado al guardarlo. Devuelve cuántas veces
     * seguidas ha fallado ese mismo correo (otro correo empieza de cero).
     */
    public function recordMessageFailure(string $messageId): int
    {
        $this->failing_message_attempts = $this->failing_message_id === $messageId
            ? $this->failing_message_attempts + 1
            : 1;
        $this->failing_message_id = $messageId;
        $this->save();

        return $this->failing_message_attempts;
    }

    /** Intento fallido: el cursor NO avanza (el siguiente intento repite el lote). */
    public function recordFailure(string $error): void
    {
        $this->last_synced_at = now();
        $this->last_error = Str::limit($error, 1000);
        $this->consecutive_failures++;

        $this->save();
        $this->fireModelEvent('syncFailed', false);
    }
}
