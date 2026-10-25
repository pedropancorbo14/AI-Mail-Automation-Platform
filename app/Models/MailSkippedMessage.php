<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Correo que la sincronización ha apartado porque falla siempre al
 * guardarlo (mensaje "envenenado"). Lo crea solo MailSyncService.
 */
class MailSkippedMessage extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<MailConnection, $this> */
    public function mailConnection(): BelongsTo
    {
        return $this->belongsTo(MailConnection::class);
    }
}
