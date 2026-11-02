<?php

namespace App\Billing\Observers;

use App\Billing\UsageMeter;
use App\Models\Email;

/** Cuenta los correos guardados por la ingesta en el uso del mes. */
class EmailUsageObserver
{
    /** Un guardado deshecho no cuenta. */
    public bool $afterCommit = true;

    public function __construct(private readonly UsageMeter $meter)
    {
    }

    public function created(Email $email): void
    {
        $this->meter->increment($email->tenant_id, UsageMeter::EMAILS_RECEIVED);
    }
}
