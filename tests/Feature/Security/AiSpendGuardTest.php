<?php

use App\AI\Remote\Contracts\EventStream;
use App\Automation\AutomationMode;
use App\Automation\EmailProcessor;
use App\Mail\Enums\EmailStatus;
use App\Models\AiRequest;
use App\Models\Email;
use App\Models\Tenant;

/*
 * Freno del gasto de IA: presupuesto diario por empresa y límite de emails
 * por remitente. Superado, el email va a una persona sin llamar a la IA.
 */

beforeEach(function () {
    config([
        'ai.limits.tenant_daily_budget_usd' => 5.0,
        'ai.limits.max_ai_emails_per_sender_per_day' => 3,
    ]);
    $this->streams = app(EventStream::class);

    $this->tenant = Tenant::factory()->create(['slug' => 'acme']);
    $this->tenant->configureAutomation(AutomationMode::Draft);
});

function spend(Tenant $tenant, float $usd, ?Carbon\CarbonInterface $at = null): void
{
    inTenant($tenant, function () use ($usd, $at) {
        $request = AiRequest::create([
            'purpose' => 'email_agent', 'provider' => 'anthropic', 'requested_model' => 'claude-opus-5-5',
            'status' => 'success', 'cost_usd' => $usd,
        ]);

        if ($at) {
            $request->forceFill(['created_at' => $at])->save();
        }
    });
}

function guardedEmail(Tenant $tenant, string $from = 'cliente@cliente.test', array $attributes = []): Email
{
    return inTenant($tenant, fn () => Email::factory()->create(['from_email' => $from, 'subject' => 'Hola', ...$attributes]));
}

function processGuarded(Tenant $tenant, Email $email): ?EmailStatus
{
    return inTenant($tenant, fn () => app(EmailProcessor::class)->process($email->fresh()));
}

/** Emails que se mandaron a ai-service. */
function publishedToAi(): int
{
    return count(test()->streams->entries['email.received.v1'] ?? []);
}

function guardReason(Tenant $tenant, Email $email): ?string
{
    return inTenant($tenant, fn () => $email->statusChanges()->get()->last()?->reason);
}

it('sends the email to a person without calling the ai once the daily budget is spent', function () {
    spend($this->tenant, 3.10);
    spend($this->tenant, 1.95);
    $email = guardedEmail($this->tenant);

    expect(processGuarded($this->tenant, $email))->toBe(EmailStatus::HumanReview)
        ->and(guardReason($this->tenant, $email))->toBe('La empresa ha alcanzado su presupuesto diario de IA (5.05 $ de 5.00 $): lo atiende una persona.')
        ->and(publishedToAi())->toBe(0)
        ->and($email->fresh()->classified_at)->toBeNull()
        ->and(metricSample('ai_guard_blocks_total', ['tenant' => 'acme', 'reason' => 'budget']))->toBe(1.0);
});

it('uses the ai while there is budget left, counting only today and only this company', function () {
    spend($this->tenant, 4.00);
    spend($this->tenant, 50.00, now()->subDay()->endOfDay()); // ayer
    spend(Tenant::factory()->create(), 50.00);                 // otra empresa

    expect(processGuarded($this->tenant, guardedEmail($this->tenant)))->toBe(EmailStatus::Processing)
        ->and(publishedToAi())->toBe(1);
});

it('lets the operator give a company its own budget, or none', function () {
    spend($this->tenant, 6.00);

    $this->tenant->forceFill(['ai_daily_budget_usd' => 20])->save();
    processGuarded($this->tenant, guardedEmail($this->tenant, 'a@cliente.test'));
    expect(publishedToAi())->toBe(1);

    config(['ai.limits.tenant_daily_budget_usd' => null]);
    $this->tenant->forceFill(['ai_daily_budget_usd' => null])->save();
    processGuarded($this->tenant, guardedEmail($this->tenant, 'b@cliente.test'));
    expect(publishedToAi())->toBe(2);
});

it('stops using the ai for a sender that floods the mailbox, ignoring case', function () {
    guardedEmail($this->tenant, 'Pesado@Cliente.test', ['classified_at' => now()->subHours(2)]);
    guardedEmail($this->tenant, 'pesado@cliente.test', ['classified_at' => now()->subHours(2)]);
    guardedEmail($this->tenant, 'PESADO@cliente.test', ['ai_requested_at' => now()->subMinute()]); // aún en ai-service
    guardedEmail($this->tenant, 'pesado@cliente.test', ['classified_at' => now()->subDays(2), 'created_at' => now()->subDays(2)]); // hace más de 24 h
    $email = guardedEmail($this->tenant, 'pesado@cliente.test');

    expect(processGuarded($this->tenant, $email))->toBe(EmailStatus::HumanReview)
        ->and(guardReason($this->tenant, $email))->toBe('pesado@cliente.test ha enviado más de 3 emails en 24 h: los siguientes los atiende una persona, sin IA.')
        ->and(publishedToAi())->toBe(0)
        ->and(metricSample('ai_guard_blocks_total', ['tenant' => 'acme', 'reason' => 'sender']))->toBe(1.0);
});

it('does not count emails that did not reach the ai, nor other senders or companies', function () {
    guardedEmail($this->tenant, 'pesado@cliente.test');                                           // sin clasificar (p. ej. ya frenado)
    guardedEmail($this->tenant, 'pesado@cliente.test');
    guardedEmail($this->tenant, 'otro@cliente.test', ['classified_at' => now()]);
    guardedEmail(Tenant::factory()->create(), 'pesado@cliente.test', ['classified_at' => now()]);
    guardedEmail($this->tenant, 'pesado@cliente.test', ['classified_at' => now()]);

    processGuarded($this->tenant, guardedEmail($this->tenant, 'pesado@cliente.test'));

    expect(publishedToAi())->toBe(1);
});

it('shows and changes the budget of a company with ai:budget', function () {
    spend($this->tenant, 1.25);

    $this->artisan('ai:budget', ['tenant' => $this->tenant->id])
        ->expectsOutputToContain('5.00 $')
        ->assertSuccessful();

    $this->artisan('ai:budget', ['tenant' => $this->tenant->id, '--daily' => '25'])->assertSuccessful();
    expect($this->tenant->fresh()->ai_daily_budget_usd)->toBe('25.00');

    $this->artisan('ai:budget', ['tenant' => $this->tenant->id, '--daily' => 'mucho'])->assertFailed();
    $this->artisan('ai:budget', ['tenant' => $this->tenant->id, '--daily' => 'default'])->assertSuccessful();
    expect($this->tenant->fresh()->ai_daily_budget_usd)->toBeNull();
});

it('does not let a company change its own budget through the api', function () {
    $owner = App\Models\User::factory()->for($this->tenant)->owner()->create();

    $this->actingAs($owner, 'sanctum')->patchJson('/api/v1/tenant', ['name' => 'Acme', 'ai_daily_budget_usd' => 9999])->assertOk();
    $this->actingAs($owner, 'sanctum')->patchJson('/api/v1/automation', ['ai_daily_budget_usd' => 9999])->assertOk();

    expect($this->tenant->fresh()->ai_daily_budget_usd)->toBeNull();
});
