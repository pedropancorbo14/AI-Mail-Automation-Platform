<?php

use App\AI\Remote\Contracts\EventStream;
use App\Automation\AutomationMode;
use App\Automation\EmailProcessor;
use App\Billing\PlanCatalog;
use App\Billing\SubscriptionStatus;
use App\Billing\UsageMeter;
use App\Mail\Enums\EmailStatus;
use App\Mail\Enums\MailConnectionStatus;
use App\Models\AiRequest;
use App\Models\Document;
use App\Models\Email;
use App\Models\MailConnection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/*
 * Límites del plan (Fase 17, tarea 3). Lo que la empresa tiene a la vez se
 * frena con un 402; la IA, mandando el email a una persona (nunca se pierde).
 */

beforeEach(function () {
    $this->tenant = Tenant::factory()->onPlan('trial', 'trialing')->create();
    $this->owner = User::factory()->for($this->tenant)->owner()->create();
});

function limitedMailboxPayload(string $email): array
{
    return [
        'name' => 'Soporte',
        'email' => $email,
        'provider' => 'imap',
        'imap' => ['host' => '8.8.8.8', 'port' => 993, 'encryption' => 'ssl', 'username' => $email, 'password' => 'secreto-imap'],
        'smtp' => ['host' => '1.1.1.1', 'port' => 587, 'encryption' => 'starttls', 'username' => $email, 'password' => 'secreto-smtp'],
    ];
}

it('does not add more users than the plan allows', function () {
    User::factory()->for($this->tenant)->agent()->count(2)->create(); // 3 de 3

    $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/v1/users', ['name' => 'Bea', 'email' => 'bea@acme.test', 'password' => 'una-contraseña-larga', 'role' => 'agent'])
        ->assertStatus(402)
        ->assertJsonPath('code', 'plan_limit')
        ->assertJsonPath('limit', 'users')
        ->assertJsonPath('plan', 'trial')
        ->assertJsonPath('message', 'Tu plan «Prueba» permite 3 usuarios. Cambia de plan en «Plan y uso» para añadir más.');

    expect($this->tenant->users()->count())->toBe(3);

    $this->tenant->assignPlan(PlanCatalog::get('starter'), SubscriptionStatus::Active);
    $this->actingAs($this->owner->fresh(), 'sanctum')
        ->postJson('/api/v1/users', ['name' => 'Bea', 'email' => 'bea@acme.test', 'password' => 'una-contraseña-larga', 'role' => 'agent'])
        ->assertCreated();
});

it('does not add more mailboxes than the plan allows, but disabled ones do not count', function () {
    $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/mail-connections', limitedMailboxPayload('uno@acme.test'))->assertCreated();
    $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/mail-connections', limitedMailboxPayload('dos@acme.test'))
        ->assertStatus(402)
        ->assertJsonPath('limit', 'mailboxes');

    $first = inTenant($this->tenant, fn () => MailConnection::sole());
    $this->actingAs($this->owner, 'sanctum')->patchJson("/api/v1/mail-connections/{$first->id}", ['enabled' => false])->assertOk();
    $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/mail-connections', limitedMailboxPayload('dos@acme.test'))->assertCreated();

    // Volver a activar el primero ocuparía un segundo hueco.
    $this->actingAs($this->owner, 'sanctum')->patchJson("/api/v1/mail-connections/{$first->id}", ['enabled' => true])
        ->assertStatus(402);
    expect(inTenant($this->tenant, fn () => $first->fresh()->status))->toBe(MailConnectionStatus::Disabled);
});

it('does not upload more documents than the plan allows', function () {
    inTenant($this->tenant, fn () => Document::factory()->count(20)->create());

    $this->actingAs($this->owner, 'sanctum')
        ->post('/api/v1/documents', ['file' => UploadedFile::fake()->createWithContent('faq.txt', 'Preguntas frecuentes')], ['Accept' => 'application/json'])
        ->assertStatus(402)
        ->assertJsonPath('limit', 'documents');

    expect(inTenant($this->tenant, fn () => Document::count()))->toBe(20);
});

describe('the AI', function () {
    beforeEach(function () {
        $this->streams = app(EventStream::class);
        $this->tenant->configureAutomation(AutomationMode::Auto);
        $this->email = inTenant($this->tenant, fn () => Email::factory()->create([
            'mail_connection_id' => MailConnection::factory()->create(['status' => MailConnectionStatus::Active]),
            'from_email' => 'cliente@cliente.test',
        ]));
    });

    $process = fn () => inTenant(test()->tenant, fn () => app(EmailProcessor::class)->process(test()->email->fresh()));
    $reason = fn () => inTenant(test()->tenant, fn () => test()->email->statusChanges()->get()->last()?->reason);

    it('works during the trial', function () use ($process) {
        $this->tenant->assignPlan(PlanCatalog::get('trial'), SubscriptionStatus::Trialing, 14);

        expect($process())->toBe(EmailStatus::Processing)
            ->and($this->streams->entries['email.received.v1'] ?? [])->toHaveCount(1);
    });

    it('stops when the trial ends, and the email goes to a person', function () use ($process, $reason) {
        $this->tenant->assignPlan(PlanCatalog::get('trial'), SubscriptionStatus::Trialing);
        $this->tenant->forceFill(['trial_ends_at' => now()->subDay()])->save();

        expect($process())->toBe(EmailStatus::HumanReview)
            ->and($reason())->toContain('periodo de prueba terminó')
            ->and($this->streams->entries['email.received.v1'] ?? [])->toBe([])
            ->and(metricSample('ai_guard_blocks_total', ['reason' => 'subscription']))->toBe(1.0);
    });

    it('stops when the company used the AI emails of the month', function () use ($process, $reason) {
        app(UsageMeter::class)->increment($this->tenant->id, UsageMeter::AI_EMAILS, 200);

        expect($process())->toBe(EmailStatus::HumanReview)
            ->and($reason())->toBe('La empresa ha usado los 200 emails con IA de este mes de su plan «Prueba»: lo atiende una persona.')
            ->and($this->streams->entries['email.received.v1'] ?? [])->toBe([]);
    });

    it('stops when the company spent the AI budget of the month', function () use ($process, $reason) {
        inTenant($this->tenant, fn () => AiRequest::create([
            'purpose' => 'ai_service.email_draft', 'provider' => 'anthropic', 'requested_model' => 'claude-sonnet-5-5',
            'status' => 'success', 'cost_usd' => 5,
        ]));

        expect($process())->toBe(EmailStatus::HumanReview)
            ->and($reason())->toContain('gasto de IA de este mes');
    });

    it('stops with a cancelled subscription', function () use ($process, $reason) {
        $this->tenant->assignPlan(PlanCatalog::get('starter'), SubscriptionStatus::Canceled);

        expect($process())->toBe(EmailStatus::HumanReview)
            ->and($reason())->toContain('cancelada');
    });

    it('has no monthly limit on the enterprise plan', function () use ($process) {
        $this->tenant->assignPlan(PlanCatalog::get('enterprise'), SubscriptionStatus::Active);
        app(UsageMeter::class)->increment($this->tenant->id, UsageMeter::AI_EMAILS, 100000);

        expect($process())->toBe(EmailStatus::Processing);
    });
});
