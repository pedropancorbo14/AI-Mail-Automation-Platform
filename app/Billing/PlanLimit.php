<?php

namespace App\Billing;

/** Límites de un plan (config/plans.php → limits). */
enum PlanLimit: string
{
    case AiEmailsPerMonth = 'ai_emails_per_month';
    case AiBudgetUsdPerMonth = 'ai_budget_usd_per_month';
    case Mailboxes = 'mailboxes';
    case Users = 'users';
    case Documents = 'documents';

    public function label(): string
    {
        return match ($this) {
            self::AiEmailsPerMonth => 'Emails con IA al mes',
            self::AiBudgetUsdPerMonth => 'Gasto de IA al mes',
            self::Mailboxes => 'Buzones',
            self::Users => 'Usuarios',
            self::Documents => 'Documentos',
        };
    }
}
