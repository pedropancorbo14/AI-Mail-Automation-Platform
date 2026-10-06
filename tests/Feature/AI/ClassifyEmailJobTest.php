<?php

use App\AI\Classification\ClassifyEmailJob;
use App\AI\Classification\EmailIntent;
use App\AI\Contracts\LLMProvider;
use App\AI\Enums\AiRequestStatus;
use App\AI\Exceptions\LLMConfigurationException;
use App\AI\Exceptions\LLMRefusalException;
use App\AI\Exceptions\LLMUnavailableException;
use App\Mail\Ingestion\Events\EmailIngested;
use App\Models\AiRequest;
use App\Models\Email;
use App\Models\Tenant;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeLLMProvider;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->email = inTenant($this->tenant, fn () => Email::factory()->create());
    $this->llm = new FakeLLMProvider();
    app()->instance(LLMProvider::class, $this->llm);
});

/** Ejecuta el job como un worker (sin tenant en contexto), con interacciones de cola simuladas. */
function runClassifyJob(bool $force = false): ClassifyEmailJob
{
    $job = (new ClassifyEmailJob(test()->tenant->id, test()->email->id, $force))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

function classifiedEmail(): Email
{
    return inTenant(test()->tenant, fn () => test()->email->fresh());
}

it('goes to the ai queue, once per email', function () {
    Queue::fake();

    ClassifyEmailJob::dispatch($this->tenant->id, $this->email->id);
    ClassifyEmailJob::dispatch($this->tenant->id, $this->email->id);

    Queue::assertPushedOn('ai', ClassifyEmailJob::class);
    Queue::assertPushed(ClassifyEmailJob::class, 1);
});

it('retries with backoff within the queue timeout', function () {
    $job = new ClassifyEmailJob(1, 1);

    expect($job->tries)->toBe(4)
        ->and($job->backoff())->toBe([30, 120, 300])
        ->and($job->timeout)->toBeLessThan(config('queue.connections.redis.retry_after'))
        ->and(config('horizon.defaults.supervisor-ai.queue'))->toBe(['ai'])
        ->and(config('horizon.defaults.supervisor-ai.timeout'))->toBeGreaterThan($job->timeout);
});

it('classifies the email inside its company and stores the result', function () {
    $this->llm->respondWith(classificationData());

    runClassifyJob()->assertNotFailed();

    expect(classifiedEmail()->intent)->toBe(EmailIntent::OrderStatus)
        ->and(classifiedEmail()->classification_ai_request_id)->toBe(inTenant($this->tenant, fn () => AiRequest::sole()->id));
});

it('does not pay twice for an email already classified', function () {
    $this->llm->respondWith(classificationData(), classificationData(['intent' => 'complaint']));

    runClassifyJob();
    runClassifyJob();

    expect($this->llm->requests)->toHaveCount(1)
        ->and(classifiedEmail()->intent)->toBe(EmailIntent::OrderStatus);

    runClassifyJob(force: true);

    expect(classifiedEmail()->intent)->toBe(EmailIntent::Complaint);
});

it('lets temporary errors bubble up so the queue retries them', function () {
    $this->llm->respondWith(new LLMUnavailableException('Anthropic no está disponible (529).'));

    expect(fn () => runClassifyJob())->toThrow(LLMUnavailableException::class);

    expect(classifiedEmail()->classified_at)->toBeNull();
});

it('fails without retrying errors that would repeat', function (Throwable $error) {
    $this->llm->respondWith($error);

    $job = runClassifyJob();

    $job->assertFailedWith($error::class);
    expect(classifiedEmail()->classified_at)->toBeNull()
        ->and(inTenant($this->tenant, fn () => AiRequest::sole()->status))->not->toBe(AiRequestStatus::Success);
})->with([
    'rechazo de seguridad' => fn () => new LLMRefusalException('Rechazado.'),
    'clave mala' => fn () => new LLMConfigurationException('Anthropic rechazó la API key (401).'),
]);

it('does nothing for deleted emails or inactive companies', function () {
    inTenant($this->tenant, fn () => $this->email->delete());
    runClassifyJob()->assertNotFailed();

    $this->tenant->update(['is_active' => false]);
    runClassifyJob()->assertNotFailed();

    expect($this->llm->requests)->toBe([]);
});

it('queues the classification when an email is ingested', function () {
    Queue::fake();
    config(['ai.anthropic.api_key' => 'sk-ant-x']);

    EmailIngested::dispatch($this->tenant->id, $this->email->id);

    Queue::assertPushed(ClassifyEmailJob::class, fn (ClassifyEmailJob $job) => $job->emailId === $this->email->id && $job->tenantId === $this->tenant->id);
});

it('leaves emails unclassified while the ai is not configured or disabled', function () {
    Queue::fake();

    EmailIngested::dispatch($this->tenant->id, $this->email->id);

    config(['ai.anthropic.api_key' => 'sk-ant-x', 'ai.classification.on_ingest' => false]);
    EmailIngested::dispatch($this->tenant->id, $this->email->id);

    Queue::assertNotPushed(ClassifyEmailJob::class);
});
