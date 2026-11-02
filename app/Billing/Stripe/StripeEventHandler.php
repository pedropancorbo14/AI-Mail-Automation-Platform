<?php

namespace App\Billing\Stripe;

use App\Billing\PlanCatalog;
use App\Billing\SubscriptionStatus;
use App\Models\Tenant;
use App\Security\Audit\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Aplica los avisos de Stripe a la suscripción de cada empresa. Llegan
 * firmados (StripeSignature) y pueden repetirse o llegar desordenados:
 *
 *  - Repetidos: billing_events con el id de Stripe único → una sola vez.
 *  - Desordenados: un aviso de suscripción más antiguo que el último aplicado
 *    (tenants.billing_event_at) no se aplica ("stale").
 *  - De quién: la empresa sale de los metadatos que pusimos al crear el pago
 *    (tenant_id) o del cliente de Stripe ya guardado. Si una empresa ya tiene
 *    otro cliente de Stripe, el aviso no se aplica (log crítico).
 */
class StripeEventHandler
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * @param  array<string, mixed>  $event
     * @return string applied | ignored | stale | duplicate
     */
    public function handle(array $event): string
    {
        $id = (string) ($event['id'] ?? '');
        $type = (string) ($event['type'] ?? '');
        $object = $event['data']['object'] ?? [];

        if ($id === '' || ! is_array($object)) {
            return 'ignored';
        }

        try {
            return DB::transaction(function () use ($id, $type, $object, $event) {
                [$result, $tenant] = $this->apply($type, $object, Carbon::createFromTimestamp((int) ($event['created'] ?? time())));

                DB::table('billing_events')->insert([
                    'provider' => 'stripe',
                    'event_id' => $id,
                    'type' => $type,
                    'tenant_id' => $tenant?->id,
                    'result' => $result,
                    'created_at' => now(),
                ]);

                return $result;
            });
        } catch (UniqueConstraintViolationException) {
            return 'duplicate';
        }
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array{0: string, 1: ?Tenant}
     */
    private function apply(string $type, array $object, Carbon $at): array
    {
        return match ($type) {
            'checkout.session.completed' => $this->checkoutCompleted($object),
            'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted' => $this->subscriptionChanged($type, $object, $at),
            'invoice.payment_failed' => $this->paymentFailed($object),
            default => ['ignored', null],
        };
    }

    /** Pago terminado: guarda el cliente y la suscripción de Stripe de la empresa. */
    private function checkoutCompleted(array $session): array
    {
        $tenant = $this->tenantFor($session['metadata']['tenant_id'] ?? $session['client_reference_id'] ?? null, $session['customer'] ?? null);

        if ($tenant === null) {
            return ['ignored', null];
        }

        $tenant->billing_customer_id = $session['customer'] ?? $tenant->billing_customer_id;
        $tenant->billing_subscription_id = $session['subscription'] ?? $tenant->billing_subscription_id;
        $tenant->save();

        return ['applied', $tenant];
    }

    private function subscriptionChanged(string $type, array $subscription, Carbon $at): array
    {
        $tenant = $this->tenantFor($subscription['metadata']['tenant_id'] ?? null, $subscription['customer'] ?? null);

        if ($tenant === null) {
            return ['ignored', null];
        }

        $tenant = Tenant::whereKey($tenant->id)->lockForUpdate()->first();

        if ($tenant->billing_event_at !== null && $at->lt($tenant->billing_event_at)) {
            return ['stale', $tenant];
        }

        // Avisos de una suscripción anterior (p. ej. se canceló y contrató otra)
        // no cambian la actual; solo una suscripción nueva (created) la sustituye.
        if ($tenant->billing_subscription_id && $tenant->billing_subscription_id !== ($subscription['id'] ?? null)
            && $type !== 'customer.subscription.created') {
            return ['ignored', $tenant];
        }

        $status = $type === 'customer.subscription.deleted'
            ? SubscriptionStatus::Canceled
            : self::status((string) ($subscription['status'] ?? ''));

        if ($status === null) {
            // incomplete: el primer pago aún no ha terminado; se espera al siguiente aviso.
            return ['ignored', $tenant];
        }

        $price = $subscription['items']['data'][0]['price']['id'] ?? null;
        $plan = $price ? PlanCatalog::forStripePrice($price) : null;

        if ($plan === null) {
            Log::warning('Suscripción de Stripe con un precio que no es de ningún plan', ['tenant_id' => $tenant->id, 'price' => $price]);
        }

        $before = ['plan' => $tenant->plan, 'subscription_status' => $tenant->subscription_status->value];
        $periodEnd = $subscription['current_period_end'] ?? $subscription['items']['data'][0]['current_period_end'] ?? null;

        $tenant->billing_customer_id = $subscription['customer'] ?? $tenant->billing_customer_id;
        $tenant->billing_subscription_id = $subscription['id'] ?? $tenant->billing_subscription_id;
        $tenant->current_period_ends_at = $periodEnd ? Carbon::createFromTimestamp((int) $periodEnd) : $tenant->current_period_ends_at;
        $tenant->billing_event_at = $at;

        if ($status === SubscriptionStatus::Trialing && isset($subscription['trial_end'])) {
            $tenant->trial_ends_at = Carbon::createFromTimestamp((int) $subscription['trial_end']);
        }

        $tenant->assignPlan($plan ?? $tenant->currentPlan(), $status);

        if ($before !== ['plan' => $tenant->plan, 'subscription_status' => $tenant->subscription_status->value]) {
            $this->audit->record('tenant.plan_changed', $tenant, $tenant->name, changes: [
                'plan' => ['from' => $before['plan'], 'to' => $tenant->plan],
                'subscription_status' => ['from' => $before['subscription_status'], 'to' => $tenant->subscription_status->value],
            ], metadata: ['source' => 'stripe'], tenantId: $tenant->id);
        }

        return ['applied', $tenant];
    }

    /** Solo constancia: el estado (past_due) llega con customer.subscription.updated. */
    private function paymentFailed(array $invoice): array
    {
        $tenant = $this->tenantFor(null, $invoice['customer'] ?? null);

        if ($tenant) {
            $this->audit->record('billing.payment_failed', $tenant, $tenant->name, metadata: ['source' => 'stripe'], tenantId: $tenant->id);
        }

        return [$tenant ? 'applied' : 'ignored', $tenant];
    }

    private function tenantFor(mixed $tenantId, mixed $customer): ?Tenant
    {
        $tenant = is_numeric($tenantId) ? Tenant::find((int) $tenantId) : null;
        $tenant ??= is_string($customer) && $customer !== '' ? Tenant::where('billing_customer_id', $customer)->first() : null;

        if ($tenant && $tenant->billing_customer_id && is_string($customer) && $customer !== '' && $tenant->billing_customer_id !== $customer) {
            Log::critical('Aviso de Stripe para una empresa con otro cliente de Stripe: no se aplica', [
                'tenant_id' => $tenant->id,
            ]);

            return null;
        }

        return $tenant;
    }

    private static function status(string $stripe): ?SubscriptionStatus
    {
        return match ($stripe) {
            'trialing' => SubscriptionStatus::Trialing,
            'active' => SubscriptionStatus::Active,
            'past_due', 'unpaid' => SubscriptionStatus::PastDue,
            'canceled', 'incomplete_expired', 'paused' => SubscriptionStatus::Canceled,
            default => null,
        };
    }
}
