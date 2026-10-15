<?php

use App\Documents\Enums\DocumentStatus;
use App\Jobs\TestQueueJob;
use App\Mail\Enums\EmailStatus;
use App\Mail\Enums\MailConnectionStatus;
use App\Models\Document;
use App\Models\Email;
use App\Models\MailConnection;
use App\Models\MailSyncState;
use App\Models\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class MetricsFailingJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        throw new RuntimeException('falla siempre');
    }
}

describe('HTTP', function () {
    it('counts requests by route template, never by real URL', function () {
        $this->getJson('/api/v1/health')->assertOk();
        $this->getJson('/api/v1/emails/123')->assertUnauthorized();
        $this->getJson('/api/v1/emails/456')->assertUnauthorized();
        $this->getJson('/api/v1/no-existe')->assertNotFound();

        expect(metricSample('http_requests_total', ['method' => 'GET', 'route' => 'api/v1/health', 'status' => '200']))->toBe(1.0)
            ->and(metricSample('http_requests_total', ['route' => 'api/v1/emails/{email}', 'status' => '401']))->toBe(2.0)
            ->and(metricSample('http_requests_total', ['route' => 'unmatched', 'status' => '404']))->toBe(1.0)
            ->and(metricSample('http_request_duration_seconds_count', ['route' => 'api/v1/emails/{email}']))->toBe(2.0);
    });

    it('does not count the scrapes of /metrics', function () {
        config(['metrics.token' => 't']);
        $this->withToken('t')->get('/metrics')->assertOk();

        expect(metricSample('http_requests_total'))->toBeNull();
    });
});

describe('queues', function () {
    it('counts and times jobs that finish', function () {
        TestQueueJob::dispatch();

        expect(metricSample('jobs_processed_total', ['job' => 'TestQueueJob', 'result' => 'success']))->toBe(1.0)
            ->and(metricSample('job_duration_seconds_count', ['job' => 'TestQueueJob']))->toBe(1.0);
    });

    it('counts jobs that fail for good', function () {
        try {
            MetricsFailingJob::dispatch();
        } catch (RuntimeException) {
        }

        expect(metricSample('jobs_processed_total', ['job' => 'MetricsFailingJob', 'result' => 'failed']))->toBe(1.0);
    });
});

describe('current state', function () {
    beforeEach(function () {
        $this->tenant = Tenant::factory()->create(['slug' => 'acme']);
        $this->other = Tenant::factory()->create(['slug' => 'globex']);
    });

    it('publishes the emails by status right now, per company', function () {
        inTenant($this->tenant, function () {
            Email::factory()->count(2)->create()->each(fn (Email $e) => $e->forceFill(['status' => EmailStatus::HumanReview])->save());
            Email::factory()->create();
        });
        inTenant($this->other, fn () => Email::factory()->create()->forceFill(['status' => EmailStatus::Failed])->save());

        expect(metricSample('emails_current', ['tenant' => 'acme', 'status' => 'human_review']))->toBe(2.0)
            ->and(metricSample('emails_current', ['tenant' => 'acme', 'status' => 'received']))->toBe(1.0)
            ->and(metricSample('emails_current', ['tenant' => 'globex', 'status' => 'failed']))->toBe(1.0)
            ->and(metricSample('emails_current', ['tenant' => 'globex', 'status' => 'human_review']))->toBeNull();
    });

    it('publishes the mailboxes and how their sync is going', function () {
        $active = inTenant($this->tenant, fn () => MailConnection::factory()->create());
        $active->forceFill(['status' => MailConnectionStatus::Active])->save();
        $failing = inTenant($this->tenant, fn () => MailConnection::factory()->create());
        $failing->forceFill(['status' => MailConnectionStatus::Failing])->save();

        inTenant($this->tenant, function () use ($active, $failing) {
            $state = $active->syncStates()->create(['folder' => 'INBOX']);
            $state->recordSuccess('10');
            $state->recordFailure('Error 1');
            $state->recordFailure('Error 2');
            $failing->syncStates()->create(['folder' => 'INBOX'])->recordFailure('Contraseña incorrecta');
        });

        expect(metricSample('mail_connections', ['tenant' => 'acme', 'provider' => 'imap', 'status' => 'active']))->toBe(1.0)
            ->and(metricSample('mail_connections', ['tenant' => 'acme', 'provider' => 'imap', 'status' => 'failing']))->toBe(1.0)
            // Solo los buzones activos (uno con credenciales mal ya está marcado como "failing").
            ->and(metricSample('mail_sync_consecutive_failures', ['tenant' => 'acme', 'mailbox' => (string) $active->id]))->toBe(2.0)
            ->and(metricSample('mail_sync_consecutive_failures', ['mailbox' => (string) $failing->id]))->toBeNull()
            ->and(metricSample('mail_sync_last_success_timestamp_seconds', ['mailbox' => (string) $active->id]))
            ->toBe((float) inTenant($this->tenant, fn () => MailSyncState::where('mail_connection_id', $active->id)->value('last_success_at'))->getTimestamp());
    });

    it('publishes the documents by status', function () {
        inTenant($this->tenant, fn () => Document::factory()->create()->markFailed('PDF sin texto'));

        expect(metricSample('documents', ['tenant' => 'acme', 'status' => DocumentStatus::Failed->value]))->toBe(1.0);
    });

    it('publishes the queue sizes and survives a queue that does not answer', function () {
        Log::spy();
        config(['metrics.queues' => [['connection' => 'sync', 'queue' => 'default'], ['connection' => 'no-existe', 'queue' => 'x']]]);

        expect(metricSample('queue_size', ['connection' => 'sync', 'queue' => 'default']))->toBe(0.0)
            ->and(metricSample('queue_size', ['connection' => 'no-existe']))->toBeNull();

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => $message === 'No se pudo leer el tamaño de una cola');
    });
});
