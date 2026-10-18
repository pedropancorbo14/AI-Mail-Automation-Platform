<?php

use App\AI\Remote\Contracts\EventStream;
use App\Automation\AutomationMode;
use App\Automation\ProcessEmailJob;
use App\Mail\Enums\EmailStatus;
use App\Mail\Ingestion\Events\EmailIngested;
use App\Models\Email;
use App\Models\Tenant;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->streams = app(EventStream::class);

    $this->tenant = Tenant::factory()->create();
    $this->email = inTenant($this->tenant, fn () => Email::factory()->create());
});

/** Ejecuta el job como un worker (sin tenant en contexto). */
function runProcessJob(bool $reprocess = false): ProcessEmailJob
{
    $job = (new ProcessEmailJob(test()->tenant->id, test()->email->id, $reprocess))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

it('goes to the ai queue, once per email', function () {
    Queue::fake();

    ProcessEmailJob::dispatch($this->tenant->id, $this->email->id);
    ProcessEmailJob::dispatch($this->tenant->id, $this->email->id);

    Queue::assertPushedOn('ai', ProcessEmailJob::class);
    Queue::assertPushed(ProcessEmailJob::class, 1);
});

it('retries with backoff within the queue limits', function () {
    $job = new ProcessEmailJob(1, 1);

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([60, 300])
        ->and($job->connection)->toBe(config('ai.automation.queue_connection'))
        ->and($job->timeout)->toBeLessThan(config('queue.connections.redis.retry_after'))
        ->and(config('horizon.defaults.supervisor-ai.timeout'))->toBeGreaterThan($job->timeout);
});

it('processes the email inside its company: published for ai-service', function () {
    $this->tenant->configureAutomation(AutomationMode::Draft);

    runProcessJob()->assertNotFailed();

    expect($this->email->fresh()->status)->toBe(EmailStatus::Processing)
        ->and($this->streams->entries['email.received.v1'] ?? [])->toHaveCount(1);
});

it('marks the email as failed when the retries run out', function () {
    inTenant($this->tenant, fn () => $this->email->transitionTo(EmailStatus::Processing));

    (new ProcessEmailJob($this->tenant->id, $this->email->id))->failed(new RuntimeException('Redis no responde.'));

    expect($this->email->fresh()->status)->toBe(EmailStatus::Failed)
        ->and(inTenant($this->tenant, fn () => $this->email->statusChanges()->get()->last()->reason))
        ->toBe('No se pudo procesar tras varios intentos: Redis no responde.');
});

it('does nothing for deleted emails or inactive companies', function () {
    $this->tenant->update(['is_active' => false]);
    runProcessJob()->assertNotFailed();

    expect($this->email->fresh()->status)->toBe(EmailStatus::Received)
        ->and($this->streams->entries['email.received.v1'] ?? [])->toBe([]);
});

it('queues the processing when an email is ingested', function () {
    Queue::fake();

    EmailIngested::dispatch($this->tenant->id, $this->email->id);

    Queue::assertPushed(ProcessEmailJob::class, fn (ProcessEmailJob $job) => $job->emailId === $this->email->id
        && $job->tenantId === $this->tenant->id
        && ! $job->reprocess);
});

it('does not process on ingest when it is turned off', function () {
    Queue::fake();
    config(['ai.automation.process_on_ingest' => false]);

    EmailIngested::dispatch($this->tenant->id, $this->email->id);

    Queue::assertNotPushed(ProcessEmailJob::class);
});
