<?php

use App\AI\Agent\Enums\EmailReplyStatus;
use App\AI\Remote\Contracts\EventStream;
use App\AI\Remote\EmailEventPublisher;
use App\Automation\AutomationMode;
use App\Automation\EmailProcessor;
use App\Mail\Enums\EmailStatus;
use App\Mail\Enums\MailConnectionStatus;
use App\Mail\Enums\MailProviderType;
use App\Mail\MailProviderFactory;
use App\Models\Email;
use App\Models\EmailStatusChange;
use App\Models\MailConnection;
use App\Models\Tenant;
use Tests\Fakes\FakeMailProvider;

beforeEach(function () {
    $this->streams = app(EventStream::class);
    $this->mail = new FakeMailProvider();
    app(MailProviderFactory::class)->extend(MailProviderType::Imap, fn () => $this->mail);

    $this->tenant = Tenant::factory()->create(['slug' => 'empresa-demo']);
    $this->tenant->configureAutomation(AutomationMode::Auto);

    $this->email = inTenant($this->tenant, fn () => Email::factory()->create([
        'mail_connection_id' => MailConnection::factory()->create(['email' => 'soporte@empresa.test', 'status' => MailConnectionStatus::Active]),
        'from_email' => 'cliente@cliente.test',
        'subject' => '¿Hacéis envíos a Canarias?',
        'text_body' => 'Hola, ¿enviáis a Tenerife?',
    ]));
});

function processEmail(bool $reprocess = false): ?EmailStatus
{
    return inTenant(test()->tenant, fn () => app(EmailProcessor::class)->process(test()->email->fresh(), $reprocess));
}

/** @return list<string> Estados por los que ha pasado el email. */
function statusPath(): array
{
    return inTenant(test()->tenant, fn () => test()->email->statusChanges()->get()
        ->map(fn (EmailStatusChange $change) => $change->to_status->value)->all());
}

function lastStatusReason(): ?string
{
    return inTenant(test()->tenant, fn () => test()->email->statusChanges()->get()->last()?->reason);
}

/** @return list<array<string, mixed>> Eventos email.received.v1 publicados. */
function publishedEmails(): array
{
    return array_map(fn ($m) => json_decode($m->fields['payload'], true), test()->streams->entries['email.received.v1'] ?? []);
}

it('publishes the email for ai-service and waits in processing', function () {
    expect(processEmail())->toBe(EmailStatus::Processing)
        ->and(statusPath())->toBe(['processing'])
        ->and(publishedEmails())->toHaveCount(1)
        ->and(publishedEmails()[0]['email_id'])->toBe($this->email->id);
});

it('answers a safe email on its own once ai-service replies', function () {
    expect(processWithAi($this->email))->toBe(EmailStatus::Sent) // cola sync: el envío ya se ha hecho
        ->and(statusPath())->toBe(['processing', 'ai_review', 'approved', 'sent'])
        ->and($this->mail->sent)->toHaveCount(1)
        ->and($this->mail->sent[0]->textBody)->toContain('enviamos a Canarias')
        ->and($this->mail->sent[0]->autoSubmitted)->toBeTrue();

    $reply = inTenant($this->tenant, fn () => $this->email->replies()->sole());
    expect($reply->status)->toBe(EmailReplyStatus::Sent)
        ->and($reply->sent_automatically)->toBeTrue();
});

it('leaves the proposal to a person in draft mode', function () {
    $this->tenant->configureAutomation(AutomationMode::Draft);

    expect(processWithAi($this->email))->toBe(EmailStatus::HumanReview)
        ->and(statusPath())->toBe(['processing', 'ai_review', 'human_review'])
        ->and(lastStatusReason())->toContain('modo «Borradores»')
        ->and($this->mail->sent)->toBe([])
        ->and(inTenant($this->tenant, fn () => $this->email->replies()->sole()->status))->toBe(EmailReplyStatus::Draft);
});

it('ignores automated emails without spending on the ai', function () {
    inTenant($this->tenant, fn () => $this->email->forceFill(['automated_reason' => 'Mensaje automático (Auto-Submitted: auto-replied).'])->save());

    expect(processEmail())->toBe(EmailStatus::Ignored)
        ->and(lastStatusReason())->toBe('Correo automático: Mensaje automático (Auto-Submitted: auto-replied).')
        ->and(publishedEmails())->toBe([]);
});

it('goes straight to a person when the company does not use the ai', function () {
    $this->tenant->configureAutomation(AutomationMode::Off);

    expect(processEmail())->toBe(EmailStatus::HumanReview)
        ->and(statusPath())->toBe(['processing', 'human_review'])
        ->and(lastStatusReason())->toContain('automatización está desactivada')
        ->and(publishedEmails())->toBe([]);
});

it('marks the email as failed on unexpected errors', function () {
    $this->mock(EmailEventPublisher::class)->shouldReceive('publish')->andThrow(new RuntimeException('Error inesperado'));

    expect(fn () => processEmail())->toThrow(RuntimeException::class);

    expect($this->email->fresh()->status)->toBe(EmailStatus::Failed)
        ->and(lastStatusReason())->toBe('Error interno al procesar el email.');
});

it('only reprocesses decided emails when asked to', function () {
    $this->tenant->configureAutomation(AutomationMode::Draft);
    processWithAi($this->email);

    expect(processEmail())->toBeNull()
        ->and(publishedEmails())->toHaveCount(1);

    expect(processEmail(reprocess: true))->toBe(EmailStatus::Processing)
        ->and(publishedEmails())->toHaveCount(2)
        ->and(statusPath())->toBe(['processing', 'ai_review', 'human_review', 'processing']);
});

it('never reprocesses an email whose reply was already sent or attempted', function () {
    processWithAi($this->email); // enviada sola

    expect(inTenant($this->tenant, fn () => EmailProcessor::sendWasAttempted($this->email->fresh())))->toBeTrue()
        ->and(processEmail(reprocess: true))->toBeNull()
        ->and(publishedEmails())->toHaveCount(1);
});

it('does not touch emails that are already being answered', function (EmailStatus $status) {
    inTenant($this->tenant, fn () => $this->email->forceFill(['status' => $status])->save());

    expect(processEmail(reprocess: true))->toBeNull()
        ->and(publishedEmails())->toBe([]);
})->with([EmailStatus::AiReview, EmailStatus::Approved, EmailStatus::Sent]);

it('sends old emails to a person without the ai, unless a person reprocesses them', function () {
    $this->travelTo(now()->setDate(2026, 10, 25));
    inTenant($this->tenant, fn () => $this->email->forceFill(['sent_at' => now()->subDays(40)])->save());

    expect(processEmail())->toBe(EmailStatus::HumanReview)
        ->and(publishedEmails())->toBe([])
        ->and(lastStatusReason())->toBe('El correo es del 15/09/2026 (hace 40 días): un correo antiguo (p. ej. movido a la bandeja) no se responde con la IA, lo revisa una persona.');

    // Una persona decide que sí: reprocesarlo lo manda a la IA.
    expect(processEmail(reprocess: true))->toBe(EmailStatus::Processing)
        ->and(publishedEmails())->toHaveCount(1);
});

it('lets recent, undated and any email through when the age limit is off', function (?int $daysAgo, int $limit) {
    config(['ai.automation.max_email_age_days' => $limit]);
    inTenant($this->tenant, fn () => $this->email->forceFill(['sent_at' => $daysAgo === null ? null : now()->subDays($daysAgo)])->save());

    expect(processEmail())->toBe(EmailStatus::Processing)
        ->and(publishedEmails())->toHaveCount(1);
})->with([
    'de hace 6 días' => [6, 7],
    'sin fecha' => [null, 7],
    'antiguo con el límite desactivado' => [400, 0],
]);

it('still ignores old automated emails without a person', function () {
    inTenant($this->tenant, fn () => $this->email->forceFill(['sent_at' => now()->subDays(40), 'automated_reason' => 'Auto-Submitted: auto-replied'])->save());

    expect(processEmail())->toBe(EmailStatus::Ignored);
});

it('must run inside the email company', function () {
    app(EmailProcessor::class)->process($this->email);
})->throws(LogicException::class);
