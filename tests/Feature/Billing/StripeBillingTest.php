<?php

use App\Billing\Stripe\StripeSignature;
use App\Billing\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Scopes\TenantScope;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * Cobro con Stripe (Fase 17, tarea 5), con la API de Stripe simulada: página
 * de pago, portal y avisos firmados, repetidos, desordenados y de otra empresa.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'services.stripe.secret' => 'sk_test_clave',
        'services.stripe.webhook_secret' => 'whsec_secreto',
        'plans.plans.starter.stripe_price' => 'price_starter',
        'plans.plans.business.stripe_price' => 'price_business',
        'app.url' => 'https://panel.test',
    ]);

    $this->tenant = Tenant::factory()->onPlan('trial', 'trialing')->create();
    $this->owner = User::factory()->for($this->tenant)->owner()->create(['email' => 'olga@acme.test']);
});

function stripeEvent(string $type, array $object, ?int $created = null, ?string $id = null): Illuminate\Testing\TestResponse
{
    $payload = json_encode(['id' => $id ?? 'evt_'.Str::random(10), 'type' => $type, 'created' => $created ?? time(), 'data' => ['object' => $object]]);

    return test()->call('POST', '/api/v1/webhooks/stripe', server: [
        'HTTP_STRIPE_SIGNATURE' => StripeSignature::header($payload, 'whsec_secreto'),
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], content: $payload);
}

function stripeSubscription(Tenant $tenant, string $status, string $price = 'price_starter', array $overrides = []): array
{
    return array_replace_recursive([
        'id' => 'sub_1',
        'customer' => 'cus_1',
        'status' => $status,
        'metadata' => ['tenant_id' => (string) $tenant->id],
        'items' => ['data' => [['price' => ['id' => $price], 'current_period_end' => now()->addMonth()->timestamp]]],
    ], $overrides);
}

// --- Firma ------------------------------------------------------------------------

it('accepts only signatures made with the secret and recent enough', function () {
    $payload = '{"id":"evt_1"}';
    $now = 1_800_000_000;

    expect(StripeSignature::valid($payload, StripeSignature::header($payload, 's', $now), 's', 300, $now))->toBeTrue()
        ->and(StripeSignature::valid($payload, StripeSignature::header($payload, 'otro', $now), 's', 300, $now))->toBeFalse()
        ->and(StripeSignature::valid($payload.' ', StripeSignature::header($payload, 's', $now), 's', 300, $now))->toBeFalse()
        ->and(StripeSignature::valid($payload, StripeSignature::header($payload, 's', $now - 301), 's', 300, $now))->toBeFalse()
        ->and(StripeSignature::valid($payload, 'v1=abc', 's', 300, $now))->toBeFalse()
        ->and(StripeSignature::valid($payload, null, 's', 300, $now))->toBeFalse()
        ->and(StripeSignature::valid($payload, StripeSignature::header($payload, '', $now), '', 300, $now))->toBeFalse();
});

it('rejects webhooks without a valid signature and does not exist without a secret', function () {
    $this->postJson('/api/v1/webhooks/stripe', ['id' => 'evt_1', 'type' => 'customer.subscription.updated'], ['Stripe-Signature' => 't=1,v1=falsa'])
        ->assertStatus(400);

    config(['services.stripe.webhook_secret' => null]);
    $this->postJson('/api/v1/webhooks/stripe', [])->assertNotFound();
});

// --- Contratar y portal -----------------------------------------------------------

it('sends the owner to the Stripe checkout with the company in the metadata', function () {
    Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1'])]);

    $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/v1/billing/checkout', ['plan' => 'starter'])
        ->assertOk()
        ->assertJsonPath('url', 'https://checkout.stripe.com/c/pay/cs_1');

    Http::assertSent(function (Request $request) {
        $data = $request->data();

        return $request->hasHeader('Authorization', 'Bearer sk_test_clave')
            && $data['mode'] === 'subscription'
            && $data['line_items'][0]['price'] === 'price_starter'
            && $data['metadata']['tenant_id'] === (string) $this->tenant->id
            && $data['subscription_data']['metadata']['tenant_id'] === (string) $this->tenant->id
            && $data['customer_email'] === 'olga@acme.test'
            && $data['success_url'] === 'https://panel.test/plan?checkout=success';
    });
    expect(AuditLog::where('action', 'billing.checkout_started')->exists())->toBeTrue();
});

it('only lets the owner pay, and only for plans with a Stripe price', function () {
    $admin = User::factory()->for($this->tenant)->admin()->create();
    $this->actingAs($admin, 'sanctum')->postJson('/api/v1/billing/checkout', ['plan' => 'starter'])->assertForbidden();

    $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/billing/checkout', ['plan' => 'enterprise'])->assertUnprocessable();
    $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/billing/checkout', ['plan' => 'gratis'])->assertJsonValidationErrors('plan');

    config(['services.stripe.secret' => null]);
    $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/billing/checkout', ['plan' => 'starter'])->assertUnprocessable();
});

it('sends a paying company to the portal instead of a second subscription', function () {
    $this->tenant->forceFill(['billing_customer_id' => 'cus_1', 'subscription_status' => 'active'])->save();
    Http::fake(['api.stripe.com/v1/billing_portal/sessions' => Http::response(['url' => 'https://billing.stripe.com/p/session_1'])]);

    $this->actingAs($this->owner->fresh(), 'sanctum')->postJson('/api/v1/billing/checkout', ['plan' => 'business'])->assertConflict();
    $this->actingAs($this->owner->fresh(), 'sanctum')->postJson('/api/v1/billing/portal')
        ->assertOk()
        ->assertJsonPath('url', 'https://billing.stripe.com/p/session_1');

    Http::assertSent(fn (Request $request) => $request['customer'] === 'cus_1' && $request['return_url'] === 'https://panel.test/plan');
});

it('explains when Stripe fails, without details', function () {
    Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'No such price: price_starter', 'type' => 'invalid_request_error']], 400)]);

    $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/billing/checkout', ['plan' => 'starter'])
        ->assertStatus(503)
        ->assertJsonPath('message', 'El sistema de pagos rechazó la petición. Si se repite, contacta con nosotros.');

    $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/billing/portal')->assertUnprocessable();
});

// --- Avisos -----------------------------------------------------------------------

it('activates the plan when the payment and the subscription arrive', function () {
    stripeEvent('checkout.session.completed', [
        'customer' => 'cus_1', 'subscription' => 'sub_1', 'client_reference_id' => (string) $this->tenant->id,
        'metadata' => ['tenant_id' => (string) $this->tenant->id],
    ])->assertOk()->assertJsonPath('result', 'applied');

    stripeEvent('customer.subscription.created', stripeSubscription($this->tenant, 'active'))->assertOk();

    $tenant = $this->tenant->fresh();
    expect($tenant->plan)->toBe('starter')
        ->and($tenant->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($tenant->billing_customer_id)->toBe('cus_1')
        ->and($tenant->billing_subscription_id)->toBe('sub_1')
        ->and($tenant->current_period_ends_at)->not->toBeNull()
        ->and($tenant->subscriptionBlockReason())->toBeNull();

    $log = AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'tenant.plan_changed')->sole();
    expect($log->metadata)->toBe(['source' => 'stripe'])
        ->and($log->changes['plan'])->toEqualCanonicalizing(['from' => 'trial', 'to' => 'starter']);

    $this->actingAs($this->owner->fresh(), 'sanctum')->getJson('/api/v1/billing')
        ->assertJsonPath('data.billing.enabled', true)
        ->assertJsonPath('data.billing.has_subscription', true)
        ->assertJsonPath('data.plans.1.purchasable', true)
        ->assertJsonPath('data.plans.3.purchasable', false);
});

it('follows plan changes, failed payments and cancellations', function () {
    stripeEvent('customer.subscription.created', stripeSubscription($this->tenant, 'active'), now()->subMinutes(10)->timestamp);
    stripeEvent('customer.subscription.updated', stripeSubscription($this->tenant, 'active', 'price_business'), now()->subMinutes(8)->timestamp);
    expect($this->tenant->fresh()->plan)->toBe('business');

    stripeEvent('customer.subscription.updated', stripeSubscription($this->tenant, 'past_due', 'price_business'), now()->subMinutes(6)->timestamp);
    expect($this->tenant->fresh()->subscription_status)->toBe(SubscriptionStatus::PastDue)
        ->and($this->tenant->fresh()->past_due_since)->not->toBeNull();

    stripeEvent('invoice.payment_failed', ['customer' => 'cus_1'])->assertJsonPath('result', 'applied');

    stripeEvent('customer.subscription.deleted', stripeSubscription($this->tenant, 'canceled', 'price_business'), now()->subMinutes(4)->timestamp);
    expect($this->tenant->fresh()->subscription_status)->toBe(SubscriptionStatus::Canceled)
        ->and($this->tenant->fresh()->subscriptionBlockReason())->toContain('cancelada');
});

it('applies each event once and never goes back with an older one', function () {
    stripeEvent('customer.subscription.created', stripeSubscription($this->tenant, 'active'), now()->subMinutes(5)->timestamp, 'evt_a')
        ->assertJsonPath('result', 'applied');
    stripeEvent('customer.subscription.created', stripeSubscription($this->tenant, 'active'), now()->subMinutes(5)->timestamp, 'evt_a')
        ->assertJsonPath('result', 'duplicate');

    stripeEvent('customer.subscription.updated', stripeSubscription($this->tenant, 'canceled'), now()->subMinutes(20)->timestamp)
        ->assertJsonPath('result', 'stale');

    expect($this->tenant->fresh()->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and(DB::table('billing_events')->count())->toBe(2);
});

it('ignores events of an old subscription and of another Stripe customer', function () {
    stripeEvent('customer.subscription.created', stripeSubscription($this->tenant, 'active'), now()->subMinutes(5)->timestamp);

    // La suscripción vieja se borra después: la actual sigue.
    stripeEvent('customer.subscription.deleted', stripeSubscription($this->tenant, 'canceled', overrides: ['id' => 'sub_viejo']))
        ->assertJsonPath('result', 'ignored');
    // Un aviso con los metadatos de esta empresa pero de otro cliente de Stripe no se aplica.
    stripeEvent('customer.subscription.updated', stripeSubscription($this->tenant, 'canceled', overrides: ['customer' => 'cus_otro']))
        ->assertJsonPath('result', 'ignored');

    expect($this->tenant->fresh()->subscription_status)->toBe(SubscriptionStatus::Active);
});

it('ignores events it does not use', function () {
    stripeEvent('charge.refunded', ['id' => 'ch_1'])->assertOk()->assertJsonPath('result', 'ignored');
    stripeEvent('customer.subscription.updated', stripeSubscription($this->tenant, 'incomplete'))->assertJsonPath('result', 'ignored');

    expect($this->tenant->fresh()->plan)->toBe('trial');
});
