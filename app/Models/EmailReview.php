<?php

namespace App\Models;

use App\Automation\Review\ReviewDecision;
use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una decisión de una persona sobre un email en revisión humana. Solo la
 * crea App\Automation\Review\HumanReviewService.
 *
 * @property ReviewDecision $decision
 */
#[Fillable(['email_reply_id', 'user_id', 'decision', 'edited', 'reason', 'waited_seconds'])]
class EmailReview extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'decision' => ReviewDecision::class,
            'edited' => 'boolean',
            'waited_seconds' => 'integer',
        ];
    }

    /** @return BelongsTo<Email, $this> */
    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    /** @return BelongsTo<EmailReply, $this> */
    public function reply(): BelongsTo
    {
        return $this->belongsTo(EmailReply::class, 'email_reply_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
