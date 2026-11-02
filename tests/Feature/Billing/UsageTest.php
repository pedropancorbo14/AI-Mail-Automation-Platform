<?php

use App\Automation\AutomationMode;
use App\Automation\EmailProcessor;
use App\Billing\PlanLimit;
use App\Billing\UsageMeter;
use App\Billing\UsageService;
use App\Mail\Enums\EmailStatus;
use App\Mail\Enums\MailConnectionStatus;
use App\Models\AiRequest;
use App\Models\Document;
use App\Models\Email;
use App\Models\MailConnection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * Uso del mes por empresa (Fase 17, tarea 2): contadores que no se pueden
 * "liberar" borrando correos, gasto de IA del mes y recuentos.
 */

beforeEach(function () {
    $this->tenant = Tenant::factory()->onPlan('starter')->create();
    $this->tenant->configureAutomation(AutomationMode::Auto);
    $this->mailbox = inTenant($this->tenant, fn () => MailConnection::factory()->create(['status' => MailConnectionStatus::Active]));
});

function usageOf(Tenant $tenant): App\Billing\Usage
{
    return app(UsageService::class)->forTenant($tenant);
}

function usageSpend(Tenant $tenant, float $usd, ?Carbon\CarbonInterface $at = null): void
{
    inTenant($tenant, function () use ($usd, $at) {
        $request = AiRequest::create([
            'purpose' => 'ai_service.email_draft', 'provider' => 'anthropic', 'requested_model' => 'claude-sonnet-5-5',
            'status' => 'success', 'cost_usd' => $usd,
        ]);

        if ($at) {
            $request->forceFill(['created_at' => $at])->save();
        }
    });
}

function newUsageEmail(Tenant $tenant, MailConnection $mailbox): Email
{
    return inTenant($tenant, fn () => Email::factory()->create([
        'mail_connection_id' => $mailbox->id,
        'from_email' => 'cliente@cliente.test',
    ]));
}

it('counts every stored email and keeps the count when the email is deleted', function () {
    $email = newUsageEmail($this->tenant, $this->mailbox);
    newUsageEmail($this->tenant, $this->mailbox);

    expect(usageOf($this->tenant)->emailsReceived)->toBe(2);

    inTenant($this->tenant, fn () => $email->delete());

    expect(usageOf($this->tenant)->emailsReceived)->toBe(2);
});

it('does not count an email whose transaction was rolled back', function () {
    try {
        DB::transaction(function () {
            newUsageEmail($this->tenant, $this->mailbox);

            throw new RuntimeException('falla la ingesta');
        });
    } catch (RuntimeException) {
    }

    expect(usageOf($this->tenant)->emailsReceived)->toBe(0);
});

it('counts the emails sent to the AI once, and again when a person reprocesses them', function () {
    $email = newUsageEmail($this->tenant, $this->mailbox);
    $process = fn (bool $reprocess = false) => inTenant($this->tenant, fn () => app(EmailProcessor::class)->process($email->fresh(), $reprocess));

    expect($process())->toBe(EmailStatus::Processing);
    // Un reintento del job dentro de plazo no republica ni cuenta.
    $process();
    expect(usageOf($this->tenant)->used(PlanLimit::AiEmailsPerMonth))->toBe(1);

    inTenant($this->tenant, fn () => $email->fresh()->transitionTo(EmailStatus::HumanReview, 'prueba'));
    $process(true);

    expect(usageOf($this->tenant)->used(PlanLimit::AiEmailsPerMonth))->toBe(2);
});

it('does not count automated emails, which never reach the AI', function () {
    $email = inTenant($this->tenant, fn () => Email::factory()->create([
        'mail_connection_id' => $this->mailbox->id,
        'automated_reason' => 'Auto-Submitted: auto-replied',
    ]));

    inTenant($this->tenant, fn () => app(EmailProcessor::class)->process($email));

    expect(usageOf($this->tenant)->used(PlanLimit::AiEmailsPerMonth))->toBe(0)
        ->and(usageOf($this->tenant)->emailsReceived)->toBe(1);
});

it('starts again every month', function () {
    app(UsageMeter::class)->increment($this->tenant->id, UsageMeter::AI_EMAILS, 5);

    $this->travel(1)->months();

    expect(usageOf($this->tenant)->used(PlanLimit::AiEmailsPerMonth))->toBe(0)
        ->and(usageOf($this->tenant)->period)->toBe(now()->format('Y-m'));
});

it('adds the AI cost of the month and counts what the company has', function () {
    usageSpend($this->tenant, 1.25);
    usageSpend($this->tenant, 0.5);
    usageSpend($this->tenant, 9, now()->subMonth());
    inTenant($this->tenant, function () {
        MailConnection::factory()->create(['status' => MailConnectionStatus::Disabled]);
        Document::factory()->count(3)->create();
    });
    User::factory()->for($this->tenant)->count(2)->create();

    $usage = usageOf($this->tenant);

    expect($usage->used(PlanLimit::AiBudgetUsdPerMonth))->toBe(1.75)
        ->and($usage->used(PlanLimit::Mailboxes))->toBe(1)
        ->and($usage->used(PlanLimit::Users))->toBe(2)
        ->and($usage->used(PlanLimit::Documents))->toBe(3)
        ->and($usage->remaining(PlanLimit::Mailboxes))->toBe(1)
        ->and($usage->ratio(PlanLimit::Users))->toBe(0.4)
        ->and($usage->reached(PlanLimit::Mailboxes))->toBeFalse();
});

it('never mixes the usage of two companies', function () {
    $other = Tenant::factory()->onPlan('starter')->create();
    $otherMailbox = inTenant($other, fn () => MailConnection::factory()->create());
    newUsageEmail($other, $otherMailbox);
    usageSpend($other, 3);

    $usage = usageOf($this->tenant);

    expect($usage->emailsReceived)->toBe(0)
        ->and($usage->used(PlanLimit::AiBudgetUsdPerMonth))->toBe(0.0)
        ->and(usageOf($other)->emailsReceived)->toBe(1);
});

it('has no limit on the enterprise plan', function () {
    $tenant = Tenant::factory()->create();

    expect(usageOf($tenant)->remaining(PlanLimit::Users))->toBeNull()
        ->and(usageOf($tenant)->reached(PlanLimit::Users))->toBeFalse()
        ->and(usageOf($tenant)->toArray()['users']['limit'])->toBeNull();
});
