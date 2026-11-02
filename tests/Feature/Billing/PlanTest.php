<?php

use App\Billing\PlanCatalog;
use App\Billing\PlanLimit;
use App\Billing\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Tenancy\Scopes\TenantScope;

/*
 * Planes y suscripción (Fase 17, tarea 1): catálogo, plan de cada empresa,
 * cuándo la suscripción deja usar la IA y lo que cambia el operador.
 */

it('reads the plan catalog from the config', function () {
    expect(PlanCatalog::keys())->toBe(['trial', 'starter', 'business', 'enterprise']);

    $trial = PlanCatalog::get('trial');
    expect($trial->name)->toBe('Prueba')
        ->and($trial->limit(PlanLimit::Mailboxes))->toBe(1)
        ->and($trial->purchasable())->toBeFalse()
        ->and(PlanCatalog::get('enterprise')->limit(PlanLimit::AiEmailsPerMonth))->toBeNull()
        ->and($trial->toArray()['limits'])->toHaveKeys(array_column(PlanLimit::cases(), 'value'))
        ->and(PlanCatalog::find('gratis-para-siempre'))->toBeNull();
});

it('finds the plan that a Stripe price belongs to', function () {
    config(['plans.plans.starter.stripe_price' => 'price_starter_123']);

    expect(PlanCatalog::forStripePrice('price_starter_123')?->key)->toBe('starter')
        ->and(PlanCatalog::get('starter')->purchasable())->toBeTrue()
        ->and(PlanCatalog::forStripePrice('price_desconocido'))->toBeNull();
});

it('falls back to the most restrictive plan when the key is unknown', function () {
    $tenant = Tenant::factory()->onPlan('plan-borrado')->create();

    expect($tenant->currentPlan()->key)->toBe('trial');
});

it('creates new companies on trial and keeps the plan out of mass assignment', function () {
    $tenant = Tenant::create(['name' => 'Nueva', 'slug' => 'nueva', 'plan' => 'enterprise', 'subscription_status' => 'active']);

    expect($tenant->fresh()->plan)->toBe('trial')
        ->and($tenant->fresh()->subscription_status)->toBe(SubscriptionStatus::Trialing);
});

it('decides whether the subscription allows the AI', function () {
    $tenant = Tenant::factory()->create();
    expect($tenant->subscriptionBlockReason())->toBeNull();

    $tenant->assignPlan(PlanCatalog::get('trial'), SubscriptionStatus::Trialing, 14);
    expect($tenant->subscriptionBlockReason())->toBeNull();

    $this->travel(15)->days();
    expect($tenant->subscriptionBlockReason())->toContain('periodo de prueba terminó');
    $this->travelBack();

    $tenant->assignPlan(PlanCatalog::get('starter'), SubscriptionStatus::PastDue);
    expect($tenant->subscriptionBlockReason())->toBeNull();
    $this->travel(8)->days();
    expect($tenant->fresh()->subscriptionBlockReason())->toContain('último pago');
    $this->travelBack();

    $tenant->assignPlan(PlanCatalog::get('starter'), SubscriptionStatus::Canceled);
    expect($tenant->subscriptionBlockReason())->toContain('cancelada')
        ->and($tenant->past_due_since)->toBeNull();
});

it('keeps the start of past_due while it lasts', function () {
    $tenant = Tenant::factory()->create();
    $tenant->assignPlan(PlanCatalog::get('starter'), SubscriptionStatus::PastDue);
    $since = $tenant->past_due_since;

    $this->travel(2)->days();
    $tenant->assignPlan(PlanCatalog::get('starter'), SubscriptionStatus::PastDue);

    expect($tenant->past_due_since->equalTo($since))->toBeTrue();
});

it('lets the operator create a company on a plan', function () {
    $this->artisan('tenant:create', ['name' => 'Acme', 'email' => 'ana@acme.example', '--generate-password' => true, '--plan' => 'business'])
        ->assertSuccessful();
    expect(Tenant::where('slug', 'acme')->sole())
        ->plan->toBe('business')
        ->subscription_status->toBe(SubscriptionStatus::Active);

    $this->artisan('tenant:create', ['name' => 'Globex', 'email' => 'ana@globex.example', '--generate-password' => true, '--trial-days' => 30])
        ->assertSuccessful();
    $globex = Tenant::where('slug', 'globex')->sole();
    expect($globex->plan)->toBe('trial')
        ->and((int) round(now()->diffInDays($globex->trial_ends_at)))->toBe(30);

    $this->artisan('tenant:create', ['name' => 'Initech', 'email' => 'ana@initech.example', '--generate-password' => true, '--plan' => 'gratis'])
        ->expectsOutputToContain('El plan no existe')
        ->assertFailed();
});

it('lets the operator change a plan and audits it', function () {
    $tenant = Tenant::factory()->onPlan('trial', 'trialing')->create(['slug' => 'acme']);

    $this->artisan('tenant:plan', ['tenant' => 'acme', '--plan' => 'business', '--status' => 'active'])
        ->expectsOutputToContain('Empresa (business)')
        ->assertSuccessful();

    expect($tenant->fresh()->plan)->toBe('business');

    $log = AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'tenant.plan_changed')->sole();
    expect($log->tenant_id)->toBe($tenant->id)
        ->and($log->changes['plan'])->toEqualCanonicalizing(['from' => 'trial', 'to' => 'business']);

    $this->artisan('tenant:plan', ['tenant' => (string) $tenant->id, '--status' => 'pagado'])
        ->expectsOutputToContain('Estado no válido')
        ->assertFailed();
    $this->artisan('tenant:plan', ['tenant' => 'no-existe'])->assertFailed();
});
