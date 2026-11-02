<?php

use App\Billing\Stripe\StripeSignature;
use App\Billing\UsageMeter;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/* Métricas de planes y suscripciones para el operador (Fase 17, tarea 8). */

it('publishes the plan, the trial end and the usage ratio of each active company', function () {
    $tenant = Tenant::factory()->onPlan('trial', 'trialing')->create(['slug' => 'acme']);
    $tenant->forceFill(['trial_ends_at' => now()->addDays(3)])->save();
    app(UsageMeter::class)->increment($tenant->id, UsageMeter::AI_EMAILS, 150);
    User::factory()->for($tenant)->count(3)->create();
    Tenant::factory()->create(['slug' => 'enterprise-co']);
    Tenant::factory()->inactive()->onPlan('starter')->create(['slug' => 'apagada']);

    expect(metricSample('tenant_plan', ['tenant' => 'acme', 'plan' => 'trial', 'status' => 'trialing']))->toBe(1.0)
        ->and(metricSample('tenant_plan', ['tenant' => 'enterprise-co', 'plan' => 'enterprise']))->toBe(1.0)
        ->and(metricSample('tenant_plan', ['tenant' => 'apagada']))->toBeNull()
        ->and(metricSample('tenant_trial_ends_timestamp_seconds', ['tenant' => 'acme']))->toBe((float) $tenant->trial_ends_at->getTimestamp())
        ->and(metricSample('tenant_plan_usage_ratio', ['tenant' => 'acme', 'limit' => 'ai_emails_per_month']))->toBe(0.75)
        ->and(metricSample('tenant_plan_usage_ratio', ['tenant' => 'acme', 'limit' => 'users']))->toBe(1.0)
        // Sin límites en el plan a medida: no hay ratio.
        ->and(metricSample('tenant_plan_usage_ratio', ['tenant' => 'enterprise-co']))->toBeNull()
        ->and(metricSample('state_metrics_ok', ['section' => 'plans']))->toBe(1.0);
});

it('counts Stripe webhooks by result, also the ones with a bad signature', function () {
    config(['services.stripe.webhook_secret' => 'whsec_secreto']);
    $payload = json_encode(['id' => 'evt_1', 'type' => 'charge.refunded', 'created' => time(), 'data' => ['object' => []]]);

    $this->call('POST', '/api/v1/webhooks/stripe', server: ['HTTP_STRIPE_SIGNATURE' => StripeSignature::header($payload, 'whsec_secreto'), 'CONTENT_TYPE' => 'application/json'], content: $payload)->assertOk();
    $this->call('POST', '/api/v1/webhooks/stripe', server: ['HTTP_STRIPE_SIGNATURE' => 't=1,v1=mala', 'CONTENT_TYPE' => 'application/json'], content: $payload)->assertStatus(400);

    expect(metricSample('billing_webhooks_total', ['result' => 'ignored']))->toBe(1.0)
        ->and(metricSample('billing_webhooks_total', ['result' => 'invalid_signature']))->toBe(1.0);
});

it('counts sign ups', function () {
    Mail::fake();
    config(['plans.signup_enabled' => true]);
    User::factory()->create(['email' => 'ya@existe.example']);
    $data = ['company' => 'Acme', 'name' => 'Ana', 'password' => 'una-contraseña-larga', 'password_confirmation' => 'una-contraseña-larga', 'accept_terms' => true];

    $this->postJson('/api/v1/signup', [...$data, 'email' => 'nueva@acme.example'])->assertStatus(202);
    $this->postJson('/api/v1/signup', [...$data, 'email' => 'ya@existe.example'])->assertStatus(202);

    expect(metricSample('tenant_signups_total', ['result' => 'created']))->toBe(1.0)
        ->and(metricSample('tenant_signups_total', ['result' => 'existing']))->toBe(1.0);
});
