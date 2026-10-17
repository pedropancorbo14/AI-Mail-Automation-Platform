<?php

use App\AI\Contracts\LLMProvider;
use App\AI\Remote\Contracts\EventStream;
use App\AI\Remote\EventContract;
use App\Automation\AutomationMode;
use App\Automation\ProcessEmailJob;
use App\Mail\Enums\EmailStatus;
use App\Models\Email;
use App\Models\Tenant;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeLLMProvider;
use Tests\Fakes\InMemoryEventStream;

const RECEIVED_STREAM = 'email.received.v1';

beforeEach(function () {
    config(['ai.engine' => 'python', 'ai.anthropic.api_key' => '']);
    $this->streams = new InMemoryEventStream;
    app()->instance(EventStream::class, $this->streams);
    $this->llm = new FakeLLMProvider;
    app()->instance(LLMProvider::class, $this->llm);

    $this->tenant = Tenant::factory()->create(['name' => 'Empresa Demo']);
    $this->tenant->configureAutomation(AutomationMode::Auto);
    $this->email = remoteEmail($this->tenant);
});

function remoteEmail(Tenant $tenant, array $attributes = []): Email
{
    return inTenant($tenant, fn () => Email::factory()->create($attributes + [
        'from_email' => 'cliente@cliente.test',
        'from_name' => 'Juan Pérez',
        'subject' => '¿Hacéis envíos a Canarias?',
        'text_body' => 'Hola, ¿enviáis a Tenerife?',
    ]));
}

it('publishes email.received.v1 and leaves the email waiting in processing', function () {
    expect(processRemote($this->email))->toBe(EmailStatus::Processing)
        ->and(remoteStatusPath($this->email))->toBe(['processing'])
        ->and($this->llm->requests)->toBe([]); // Laravel no llama a la IA

    [$message] = $this->streams->entries[RECEIVED_STREAM];
    $event = json_decode($message->payload(), true);
    app(EventContract::class)->validate('email.received.v1', $event); // cumple el contrato compartido

    $email = $this->email->fresh();
    expect($message->fields['event_type'])->toBe('email.received')
        ->and($message->fields['event_id'])->toBe($event['event_id'])
        ->and($event)->toMatchArray([
            'event_version' => 1,
            'tenant_id' => $this->tenant->id,
            'email_id' => $this->email->id,
            'from' => 'cliente@cliente.test',
            'from_name' => 'Juan Pérez',
            'subject' => '¿Hacéis envíos a Canarias?',
            'body' => 'Hola, ¿enviáis a Tenerife?',
            'body_truncated' => false,
            'tenant_name' => 'Empresa Demo',
        ])
        ->and($email->ai_event_id)->toBe($event['event_id'])
        ->and($email->ai_requested_at)->not->toBeNull()
        ->and($email->ai_request_attempts)->toBe(1);
});

it('does not need an Anthropic key in Laravel with the remote engine', function () {
    processRemote($this->email);

    expect($this->streams->entries[RECEIVED_STREAM])->toHaveCount(1)
        ->and($this->email->fresh()->status)->toBe(EmailStatus::Processing);
});

it('sends plain text: HTML is converted and long bodies are truncated with a flag', function () {
    config(['ai.classification.max_body_chars' => 50]);
    $email = remoteEmail($this->tenant, ['text_body' => null, 'html_body' => '<p>Hola <b>equipo</b></p>' . str_repeat('<p>texto largo</p>', 20)]);

    processRemote($email);

    [$event] = $this->streams->payloads(RECEIVED_STREAM);
    expect($event['body'])->toStartWith('Hola equipo')
        ->and(mb_strlen($event['body']))->toBe(50)
        ->and($event['body_truncated'])->toBeTrue();
});

it('uses the request id as trace id to follow the email in the logs', function () {
    Context::add('request_id', 'req-1234567890');

    processRemote($this->email);

    expect($this->streams->payloads(RECEIVED_STREAM)[0]['trace_id'])->toBe('req-1234567890');
});

it('never sends automated emails, disabled companies or blocked senders to the service', function (string $case) {
    match ($case) {
        'automated' => $this->email->forceFill(['automated_reason' => 'Auto-Submitted: auto-replied'])->save(),
        'mode off' => $this->tenant->configureAutomation(AutomationMode::Off),
        'budget' => config(['ai.limits.tenant_daily_budget_usd' => 0.0]),
    };

    $status = processRemote($this->email);

    expect($status)->toBeIn([EmailStatus::Ignored, EmailStatus::HumanReview])
        ->and($this->streams->entries[RECEIVED_STREAM] ?? [])->toBe([]);
})->with(['automated', 'mode off', 'budget']);

it('keeps using the Laravel engine when AI_ENGINE=laravel', function () {
    config(['ai.engine' => 'laravel']);

    processRemote($this->email); // sin clave de Anthropic: revisión humana

    expect($this->streams->entries[RECEIVED_STREAM] ?? [])->toBe([])
        ->and($this->email->fresh()->status)->toBe(EmailStatus::HumanReview);
});

it('does not publish twice when the job is retried, but does on a manual reprocess', function () {
    processRemote($this->email);
    processRemote($this->email); // reintento del job: sigue en processing

    expect($this->streams->entries[RECEIVED_STREAM])->toHaveCount(1);

    processRemote($this->email, reprocess: true);

    $ids = array_column($this->streams->payloads(RECEIVED_STREAM), 'event_id');
    expect($ids)->toHaveCount(2)->and($ids[0])->not->toBe($ids[1])
        ->and($this->email->fresh()->ai_request_attempts)->toBe(2);
});

it('keeps the email in processing if Redis fails while publishing', function () {
    Log::spy();
    $this->streams->failPublishWith = new RuntimeException('Connection refused');

    expect(processRemote($this->email))->toBe(EmailStatus::Processing)
        ->and($this->email->fresh()->ai_requested_at)->not->toBeNull(); // el reconciliador lo reintentará

    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'No se pudo publicar'))->once();
});

it('sends emails without a valid sender to a person', function () {
    $email = remoteEmail($this->tenant, ['from_email' => null]);

    expect(processRemote($email))->toBe(EmailStatus::HumanReview)
        ->and($this->streams->entries[RECEIVED_STREAM] ?? [])->toBe([]);
});

describe('ai:reconcile-remote', function () {
    function ageRequest(Email $email, int $minutes, int $attempts = 1): void
    {
        $email->forceFill(['ai_requested_at' => now()->subMinutes($minutes), 'ai_request_attempts' => $attempts])->save();
    }

    it('republishes emails without an answer with a new event', function () {
        processRemote($this->email);
        $first = $this->email->fresh()->ai_event_id;
        ageRequest($this->email, 20);

        $this->artisan('ai:reconcile-remote')->expectsOutputToContain('Republicados: 1')->assertSuccessful();

        $email = $this->email->fresh();
        expect($this->streams->entries[RECEIVED_STREAM])->toHaveCount(2)
            ->and($email->ai_event_id)->not->toBe($first)
            ->and($email->ai_request_attempts)->toBe(2)
            ->and($email->status)->toBe(EmailStatus::Processing);
    });

    it('gives up after the maximum attempts', function () {
        processRemote($this->email);
        ageRequest($this->email, 20, attempts: 3);

        $this->artisan('ai:reconcile-remote')->expectsOutputToContain('Fallidos: 1');

        expect($this->email->fresh()->status)->toBe(EmailStatus::Failed)
            ->and(remoteStatusPath($this->email))->toBe(['processing', 'failed']);
    });

    it('leaves recent requests alone and works across companies', function () {
        processRemote($this->email);
        $other = remoteEmail($globex = Tenant::factory()->create());
        $globex->configureAutomation(AutomationMode::Auto);
        processRemote($other);
        ageRequest($other, 30);

        $this->artisan('ai:reconcile-remote')->expectsOutputToContain('Republicados: 1');

        expect($this->email->fresh()->ai_request_attempts)->toBe(1)
            ->and($other->fresh()->ai_request_attempts)->toBe(2);
    });

    it('hands pending emails back to the Laravel engine if it was switched back', function () {
        processRemote($this->email);
        ageRequest($this->email, 20);
        config(['ai.engine' => 'laravel']);
        Queue::fake();

        $this->artisan('ai:reconcile-remote')->expectsOutputToContain('Al motor de Laravel: 1');

        Queue::assertPushed(ProcessEmailJob::class, fn ($job) => $job->emailId === $this->email->id);
    });

    it('is scheduled every five minutes', function () {
        $this->artisan('schedule:list')->expectsOutputToContain('ai:reconcile-remote');
    });
});
