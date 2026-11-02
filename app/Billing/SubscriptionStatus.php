<?php

namespace App\Billing;

/**
 * Estado de la suscripción de una empresa (los de Stripe que importan).
 * La IA solo trabaja si el estado lo permite (Tenant::aiAllowedReason()).
 */
enum SubscriptionStatus: string
{
    // En periodo de prueba (hasta tenants.trial_ends_at).
    case Trialing = 'trialing';
    case Active = 'active';
    // Último cobro fallido: la IA sigue unos días (plans.past_due_grace_days).
    case PastDue = 'past_due';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'En prueba',
            self::Active => 'Activa',
            self::PastDue => 'Pago pendiente',
            self::Canceled => 'Cancelada',
        };
    }
}
