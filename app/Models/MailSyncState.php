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
    ];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
            'last_success_at' => 'datetime',
            'resynced_at' => 'datetime',
            'consecutive_failures' => 'integer',
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

        if ($resynced) {
            $this->resynced_at = now();
        }

        $this->save();
    }

    /** Intento fallido: el cursor NO avanza (el siguiente intento repite el lote). */
    public function recordFailure(string $error): void
    {
        $this->last_synced_at = now();
        $this->last_error = Str::limit($error, 1000);
        $this->consecutive_failures++;

        $this->save();
    }
}
