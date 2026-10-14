<?php

namespace App\Models;

use App\Mail\Enums\EmailStatus;
use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un cambio de estado de un email. Solo lo crea Email::transitionTo().
 *
 * @property EmailStatus|null $from_status
 * @property EmailStatus $to_status
 */
#[Fillable(['from_status', 'to_status', 'reason', 'user_id'])]
class EmailStatusChange extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'from_status' => EmailStatus::class,
            'to_status' => EmailStatus::class,
        ];
    }

    /** @return BelongsTo<Email, $this> */
    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    /** @return BelongsTo<User, $this> Quién lo decidió (null = la plataforma). */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
