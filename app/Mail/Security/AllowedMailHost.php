<?php

namespace App\Mail\Security;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Regla de validación que aplica MailHostGuard a un campo host. */
class AllowedMailHost implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if ($reason = app(MailHostGuard::class)->reject($value)) {
            $fail($reason);
        }
    }
}
