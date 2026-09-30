<?php

use App\Mail\Enums\MailConnectionStatus;
use App\Mail\Enums\MailProviderType;
use App\Mail\Exceptions\MailAuthenticationException;
use App\Mail\Exceptions\MailConnectionException;
use App\Mail\Ingestion\FetchEmailsJob;
use App\Mail\MailProviderFactory;
use App\Models\Email;
use App\Models\MailConnection;
use App\Models\MailSyncState;
use App\Models\Tenant;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeMailProvider;

beforeEach(function () {
    Storage::fake('local');

    $this->tenant = Tenant::factory()->create();
    $this->connection = inTenant($this->tenant, fn () => MailConnection::factory()->create());
    $this->connection->forceFill(['status' => MailConnectionStatus::Active])->save();

    $this->provider = new FakeMailProvider();
    app(MailProviderFactory::class)->extend(MailProviderType::Imap, fn () => $this->provider);
});

/** Ejecuta el job como lo haría un worker, con las interacciones de cola simuladas. */
function runFetchJob(): FetchEmailsJob
{
    $job = (new FetchEmailsJob(test()->connection->id))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

it('goes to the mail queue, one per mailbox at a time', function () {
    Queue::fake();

    FetchEmailsJob::dispatch($this->connection->id);
    FetchEmailsJob::dispatch($this->connection->id);

    Queue::assertPushedOn('mail', FetchEmailsJob::class);
    Queue::assertPushed(FetchEmailsJob::class, 1);
});

it('retries temporary errors with backoff', function () {
    $job = new FetchEmailsJob($this->connection->id);

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([30, 120])
        ->and($job->timeout)->toBeLessThan(config('queue.connections.redis.retry_after'));
});

it('syncs the mailbox inside its tenant without a tenant in context', function () {
    runFetchJob();
    $this->provider->mailboxes['INBOX'][] = incomingMessage(['id' => '1', 'attachments' => []]);

    runFetchJob()->assertNotFailed();

    expect(inTenant($this->tenant, fn () => Email::count()))->toBe(1);
});

it('lets temporary errors bubble up so the queue retries them', function () {
    runFetchJob();
    $this->provider->failNewMessagesWith = new MailConnectionException('Tiempo de espera agotado.');

    expect(fn () => runFetchJob())->toThrow(MailConnectionException::class);
});

it('fails without retrying when the account rejects the credentials', function () {
    runFetchJob();
    $this->provider->failNewMessagesWith = new MailAuthenticationException('El servidor rechazó el usuario o la contraseña.');

    $job = runFetchJob();

    $job->assertFailedWith(MailAuthenticationException::class);
    expect($this->connection->fresh()->status)->toBe(MailConnectionStatus::Failing);
});

function assertFetchSkipped(): void
{
    runFetchJob()->assertNotFailed();

    expect(test()->provider->newMessagesCalls)->toBe([])
        ->and(MailSyncState::withoutGlobalScopes()->count())->toBe(0);
}

it('does nothing for a mailbox deleted while queued', function () {
    inTenant($this->tenant, fn () => $this->connection->delete());

    assertFetchSkipped();
});

it('does nothing for a disabled mailbox', function () {
    $this->connection->forceFill(['status' => MailConnectionStatus::Disabled])->save();

    assertFetchSkipped();
});

it('does nothing for a mailbox of a deactivated company', function () {
    $this->tenant->update(['is_active' => false]);

    assertFetchSkipped();
});
