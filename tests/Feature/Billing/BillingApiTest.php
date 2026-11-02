<?php

use App\Billing\UsageMeter;
use App\Models\Tenant;
use App\Models\User;

/* API de plan y uso (Fase 17, tarea 4). */

it('shows the plan, the subscription and the usage of the month', function () {
    $tenant = Tenant::factory()->onPlan('trial', 'trialing')->create();
    $tenant->forceFill(['trial_ends_at' => now()->addDays(10)])->save();
    $agent = User::factory()->for($tenant)->agent()->create();
    app(UsageMeter::class)->increment($tenant->id, UsageMeter::AI_EMAILS, 50);
    app(UsageMeter::class)->increment($tenant->id, UsageMeter::EMAILS_RECEIVED, 70);

    $response = $this->actingAs($agent, 'sanctum')->getJson('/api/v1/billing')->assertOk();

    $response->assertJsonPath('data.plan.key', 'trial')
        ->assertJsonPath('data.subscription.status', 'trialing')
        ->assertJsonPath('data.subscription.status_label', 'En prueba')
        ->assertJsonPath('data.subscription.ai_block_reason', null)
        ->assertJsonPath('data.usage.period', now()->format('Y-m'))
        ->assertJsonPath('data.usage.emails_received', 70)
        ->assertJsonPath('data.usage.limits.ai_emails_per_month.used', 50)
        ->assertJsonPath('data.usage.limits.ai_emails_per_month.limit', 200)
        ->assertJsonPath('data.usage.limits.ai_emails_per_month.ratio', 0.25)
        ->assertJsonPath('data.usage.limits.users.used', 1)
        ->assertJsonPath('data.limit_labels.mailboxes', 'Buzones')
        ->assertJsonCount(4, 'data.plans')
        ->assertJsonPath('data.plans.0.current', true)
        ->assertJsonPath('data.plans.1.current', false)
        ->assertJsonPath('data.usage.ai_capacity_ratio', 0);

    expect($response->json('data.subscription.trial_ends_at'))->not->toBeNull()
        // Nada de Stripe ni de identificadores internos.
        ->and($response->content())->not->toContain('stripe')
        ->and($response->content())->not->toContain('billing_customer')
        // Los costes en dólares son solo del operador (Fase 11).
        ->and(costOrTokenKeys($response->json()))->toBe([]);
});

it('shows the AI capacity used as a fraction, never in dollars', function () {
    $tenant = Tenant::factory()->onPlan('trial', 'trialing')->create();
    inTenant($tenant, fn () => App\Models\AiRequest::create([
        'purpose' => 'ai_service.email_draft', 'provider' => 'anthropic', 'requested_model' => 'claude-sonnet-5-5',
        'status' => 'success', 'cost_usd' => 1.25,
    ]));

    $response = $this->actingAs(User::factory()->for($tenant)->owner()->create(), 'sanctum')->getJson('/api/v1/billing');

    $response->assertJsonPath('data.usage.ai_capacity_ratio', 0.25);
    expect($response->content())->not->toContain('1.25')
        ->and($response->json('data.plan.limits'))->not->toHaveKey('ai_budget_usd_per_month');
});

it('explains why the AI is stopped', function () {
    $tenant = Tenant::factory()->onPlan('starter', 'canceled')->create();

    $this->actingAs(User::factory()->for($tenant)->owner()->create(), 'sanctum')
        ->getJson('/api/v1/billing')
        ->assertJsonPath('data.subscription.ai_block_reason', 'La suscripción está cancelada: elige un plan para seguir usando la IA.');
});

it('only shows the usage of the own company', function () {
    $tenant = Tenant::factory()->onPlan('starter')->create();
    $other = Tenant::factory()->onPlan('starter')->create();
    app(UsageMeter::class)->increment($other->id, UsageMeter::AI_EMAILS, 999);

    $this->actingAs(User::factory()->for($tenant)->owner()->create(), 'sanctum')
        ->getJson('/api/v1/billing')
        ->assertJsonPath('data.usage.limits.ai_emails_per_month.used', 0);
});

it('requires a session', function () {
    $this->getJson('/api/v1/billing')->assertUnauthorized();
});
