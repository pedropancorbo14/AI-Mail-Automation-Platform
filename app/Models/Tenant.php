<?php

namespace App\Models;

use App\AI\Classification\EmailIntent;
use App\Automation\AutomationMode;
use App\Billing\Plan;
use App\Billing\PlanCatalog;
use App\Billing\SubscriptionStatus;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * @property AutomationMode $automation_mode
 * @property SubscriptionStatus $subscription_status
 */
#[Fillable(['name', 'slug', 'is_active'])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    protected $attributes = [
        'automation_mode' => 'draft',
        'plan' => 'trial',
        'subscription_status' => 'trialing',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'automation_mode' => AutomationMode::class,
            'auto_send_intents' => 'array',
            // Lo fija el operador (ai:budget), nunca la propia empresa: no es asignable en masa.
            'ai_daily_budget_usd' => 'decimal:2',
            'email_retention_days' => 'integer',
            // Plan y suscripción (Fase 17): los cambia la facturación o el
            // operador (tenant:plan), nunca la empresa: no son asignables en masa.
            'subscription_status' => SubscriptionStatus::class,
            'trial_ends_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'past_due_since' => 'datetime',
            'billing_event_at' => 'datetime',
        ];
    }

    /**
     * Intenciones a las que la IA puede responder sola en modo Auto: las
     * elegidas por la empresa o, si no eligió, las de la configuración.
     *
     * @return list<EmailIntent>
     */
    public function autoSendIntents(): array
    {
        $values = $this->auto_send_intents ?? config('ai.automation.default_auto_send_intents');

        return array_values(array_filter(
            array_map(fn (string $value) => EmailIntent::tryFrom($value), $values),
            fn (?EmailIntent $intent) => $intent?->allowsAutoSend() ?? false,
        ));
    }

    /**
     * Cambia el modo de automatización y, si se indican, las intenciones de
     * envío automático ([] = volver a las de la configuración).
     *
     * @param  list<string>|null  $intents
     *
     * @throws InvalidArgumentException intención desconocida o que nunca se responde sola
     */
    public function configureAutomation(AutomationMode $mode, ?array $intents = null): void
    {
        foreach ($intents ?? [] as $value) {
            $intent = EmailIntent::tryFrom($value)
                ?? throw new InvalidArgumentException("La intención «{$value}» no existe.");

            if (! $intent->allowsAutoSend()) {
                throw new InvalidArgumentException("La IA nunca responde sola a «{$value}»: siempre lo revisa una persona.");
            }
        }

        $this->automation_mode = $mode;

        if ($intents !== null) {
            $this->auto_send_intents = $intents === [] ? null : array_values(array_unique($intents));
        }

        $this->save();
    }

    /**
     * Plan de la empresa. Si su clave ya no está en el catálogo, se aplica
     * el de prueba (el más restrictivo): nunca "sin límites" por un error de
     * configuración.
     */
    public function currentPlan(): Plan
    {
        return PlanCatalog::find($this->plan) ?? PlanCatalog::get('trial');
    }

    /**
     * Motivo por el que la suscripción no permite usar la IA, o null si la
     * permite. Sin IA los emails se siguen guardando y los atiende una persona.
     */
    public function subscriptionBlockReason(): ?string
    {
        return match ($this->subscription_status) {
            SubscriptionStatus::Active => null,
            SubscriptionStatus::Trialing => $this->trial_ends_at?->isPast()
                ? "El periodo de prueba terminó el {$this->trial_ends_at->format('d/m/Y')}: elige un plan para seguir usando la IA."
                : null,
            SubscriptionStatus::PastDue => $this->pastDueGraceEnded()
                ? 'El último pago de la suscripción falló: actualiza el método de pago para seguir usando la IA.'
                : null,
            SubscriptionStatus::Canceled => 'La suscripción está cancelada: elige un plan para seguir usando la IA.',
        };
    }

    private function pastDueGraceEnded(): bool
    {
        $since = $this->past_due_since ?? now();

        return $since->copy()->addDays((int) config('plans.past_due_grace_days'))->isPast();
    }

    /**
     * Pone la empresa en un plan. Lo usan el operador (tenant:plan), el alta
     * y la facturación. En prueba, con $trialDays fija el fin de la prueba.
     */
    public function assignPlan(Plan $plan, SubscriptionStatus $status, ?int $trialDays = null): void
    {
        $this->plan = $plan->key;
        $this->subscription_status = $status;

        if ($status === SubscriptionStatus::Trialing && $trialDays !== null) {
            $this->trial_ends_at = now()->addDays($trialDays);
        }

        $this->past_due_since = $status === SubscriptionStatus::PastDue
            ? ($this->past_due_since ?? now())
            : null;

        $this->save();
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Sigue aplicando BelongsToTenant: solo funciona con este tenant (u otro)
     * en contexto, y siempre filtra por el tenant del contexto.
     *
     * @return HasMany<MailConnection, $this>
     */
    public function mailConnections(): HasMany
    {
        return $this->hasMany(MailConnection::class);
    }
}
