<?php

namespace App\Models;

use App\Mail\Enums\RecipientType;
use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Destinatario (to/cc/bcc/reply_to) de un email. El remitente va en
 * emails.from_email / from_name.
 *
 * @property RecipientType $type
 */
#[Fillable(['type', 'email', 'name', 'position'])]
class EmailRecipient extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'type' => RecipientType::class,
        ];
    }

    /** @return BelongsTo<Email, $this> */
    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }
}
