<?php

use App\AI\Agent\Enums\EmailReplyStatus;
use App\AI\Remote\Contracts\EventStream;
use App\Automation\AutomationMode;
use App\Automation\EmailProcessor;
use App\Automation\ProcessEmailJob;
use App\Mail\Enums\EmailStatus;
use App\Models\Email;
use App\Models\Tenant;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->streams = app(EventStream::class);

    $this->tenant = Tenant::factory()->create();
    $this->email = inTenant($this->tenant, fn () => Email::factory()->create(['subject' => '¿Hacéis envíos a Canarias?', 'ingested_at' => now()]));
});

it('processes an email at once and shows how it went', function () {
    $this->tenant->configureAutomation(AutomationMode::Off);

    $this->artisan('ai:process', ['email' => $this->email->id, '--now' => true])
        ->expectsOutputToContain('Procesando «¿Hacéis envíos a Canarias?»')
        ->expectsOutputToContain('Estado actual: Revisión humana (human_review)')
        ->expectsOutputToContain('automatización está desactivada')
        ->assertSuccessful();
});

it('queues one email by default', function () {
    Queue::fake();

    $decided = inTenant($this->tenant, fn () => Email::factory()->create(['status' => EmailStatus::HumanReview]));

    $this->artisan('ai:process', ['email' => $this->email->id])->expectsOutputToContain('encolado')->assertSuccessful();
    $this->artisan('ai:process', ['email' => $decided->id, '--reprocess' => true])->assertSuccessful();

    Queue::assertPushed(ProcessEmailJob::class, fn (ProcessEmailJob $job) => $job->emailId === $this->email->id && ! $job->reprocess);
    Queue::assertPushed(ProcessEmailJob::class, fn (ProcessEmailJob $job) => $job->emailId === $decided->id && $job->reprocess);
});

it('explains when an email was already decided', function () {
    inTenant($this->tenant, fn () => $this->email->forceFill(['status' => EmailStatus::HumanReview])->save());

    $this->artisan('ai:process', ['email' => $this->email->id, '--now' => true])
        ->expectsOutputToContain('usa --reprocess')
        ->assertFailed();

    expect($this->streams->entries)->toBe([]);
});

it('never reprocesses an email whose reply was already sent or tried', function (EmailReplyStatus $replyStatus) {
    inTenant($this->tenant, function () use ($replyStatus) {
        $this->email->forceFill(['status' => EmailStatus::Failed])->save();
        draftReply($this->email)->forceFill(['status' => $replyStatus])->save();
    });

    $this->artisan('ai:process', ['email' => $this->email->id, '--now' => true, '--reprocess' => true])
        ->expectsOutputToContain('no se reprocesa para no enviarla dos veces')
        ->assertFailed();

    expect($this->streams->entries['email.received.v1'] ?? [])->toBe([])
        ->and(inTenant($this->tenant, fn () => app(EmailProcessor::class)->process($this->email->fresh(), reprocess: true)))->toBeNull();
})->with([EmailReplyStatus::Failed, EmailReplyStatus::Sent, EmailReplyStatus::Sending]);

it('queues every pending email of every company', function () {
    Queue::fake();
    $other = Tenant::factory()->create();
    $pending = inTenant($other, fn () => Email::factory()->create(['ingested_at' => now()]));
    inTenant($other, fn () => Email::factory()->create(['ingested_at' => null])); // ingesta a medias
    inTenant($other, fn () => Email::factory()->create(['ingested_at' => now(), 'status' => EmailStatus::Sent]));

    $this->artisan('ai:process', ['--pending' => true])->expectsOutputToContain('2 emails pendientes encolados')->assertSuccessful();

    Queue::assertPushed(ProcessEmailJob::class, 2);
    Queue::assertPushed(ProcessEmailJob::class, fn (ProcessEmailJob $job) => $job->emailId === $pending->id && $job->tenantId === $other->id);
});

it('queues the failed emails to be processed again', function () {
    Queue::fake();
    inTenant($this->tenant, fn () => $this->email->forceFill(['status' => EmailStatus::Failed])->save());

    $this->artisan('ai:process', ['--failed' => true])->expectsOutputToContain('1 emails con error encolados')->assertSuccessful();

    Queue::assertPushed(ProcessEmailJob::class, fn (ProcessEmailJob $job) => $job->emailId === $this->email->id && $job->reprocess);
});

it('reprocesses a failed email from the queue when nothing was sent', function () {
    $this->tenant->configureAutomation(AutomationMode::Draft);
    inTenant($this->tenant, fn () => $this->email->forceFill(['status' => EmailStatus::Failed])->save());

    $this->artisan('ai:process', ['--failed' => true])->assertSuccessful();

    expect($this->email->fresh()->status)->toBe(EmailStatus::Processing) // publicado para ai-service
        ->and($this->streams->entries['email.received.v1'])->toHaveCount(1);
});

it('validates its input', function () {
    $this->artisan('ai:process')->expectsOutputToContain('Indica un email')->assertFailed();
    $this->artisan('ai:process', ['email' => 999999])->expectsOutputToContain('No existe ese email')->assertFailed();
});
