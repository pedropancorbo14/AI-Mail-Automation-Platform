<?php

use App\Mail\Enums\MailConnectionStatus;
use App\Mail\Ingestion\FetchEmailsJob;
use App\Models\MailConnection;
use App\Models\Tenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

function connectionWithStatus(Tenant $tenant, MailConnectionStatus $status): MailConnection
{
    $connection = inTenant($tenant, fn () => MailConnection::factory()->create());
    $connection->forceFill(['status' => $status])->save();

    return $connection;
}

beforeEach(function () {
    Queue::fake();
});

it('queues a sync for every active mailbox of every active company', function () {
    $acme = Tenant::factory()->create();
    $globex = Tenant::factory()->create();
    $inactive = Tenant::factory()->create(['is_active' => false]);

    $active = [
        connectionWithStatus($acme, MailConnectionStatus::Active),
        connectionWithStatus($acme, MailConnectionStatus::Active),
        connectionWithStatus($globex, MailConnectionStatus::Active),
    ];
    connectionWithStatus($acme, MailConnectionStatus::Pending);
    connectionWithStatus($acme, MailConnectionStatus::Failing);
    connectionWithStatus($globex, MailConnectionStatus::Disabled);
    connectionWithStatus($inactive, MailConnectionStatus::Active);

    $this->artisan('mail:sync')
        ->expectsOutput('Buzones encolados para sincronizar: 3')
        ->assertSuccessful();

    Queue::assertPushed(FetchEmailsJob::class, 3);
    foreach ($active as $connection) {
        Queue::assertPushed(FetchEmailsJob::class, fn (FetchEmailsJob $job) => $job->mailConnectionId === $connection->id);
    }
});

it('can sync a single mailbox', function () {
    $tenant = Tenant::factory()->create();
    $one = connectionWithStatus($tenant, MailConnectionStatus::Active);
    connectionWithStatus($tenant, MailConnectionStatus::Active);

    $this->artisan('mail:sync', ['connection' => $one->id])->assertSuccessful();

    Queue::assertPushed(FetchEmailsJob::class, 1);
    Queue::assertPushed(FetchEmailsJob::class, fn (FetchEmailsJob $job) => $job->mailConnectionId === $one->id);
});

it('is scheduled every minute', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command, 'mail:sync'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('has a horizon supervisor for the mail queue', function () {
    expect(config('horizon.defaults.supervisor-mail.queue'))->toBe(['mail'])
        ->and(config('horizon.defaults.supervisor-mail.timeout'))->toBeGreaterThan((new FetchEmailsJob(1))->timeout)
        ->and(config('horizon.environments.local'))->toHaveKey('supervisor-mail');
});
