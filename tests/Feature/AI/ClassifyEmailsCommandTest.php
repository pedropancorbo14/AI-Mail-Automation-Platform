<?php

use App\AI\Classification\ClassifyEmailJob;
use App\AI\Contracts\LLMProvider;
use App\AI\Exceptions\LLMRefusalException;
use App\Models\Email;
use App\Models\Tenant;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeLLMProvider;

beforeEach(function () {
    config(['ai.anthropic.api_key' => 'sk-ant-x']);
    $this->llm = new FakeLLMProvider();
    app()->instance(LLMProvider::class, $this->llm);

    $this->tenant = Tenant::factory()->create();
    $this->email = inTenant($this->tenant, function () {
        $email = Email::factory()->create(['subject' => '¿Dónde está mi pedido #45821?']);
        $email->markIngested();

        return $email;
    });
});

it('refuses to run without an api key', function () {
    config(['ai.anthropic.api_key' => '']);

    $this->artisan('ai:classify', ['email' => $this->email->id])
        ->expectsOutputToContain('ANTHROPIC_API_KEY')
        ->assertFailed();
});

it('classifies an email right away and shows the result and its cost', function () {
    $this->llm->respondWith(classificationData());

    $this->artisan('ai:classify', ['email' => $this->email->id, '--now' => true])
        ->expectsOutputToContain('order_status')
        ->expectsOutputToContain('0.010800')
        ->expectsOutputToContain('El cliente pregunta dónde está su pedido #45821.')
        ->assertSuccessful();

    expect(inTenant($this->tenant, fn () => $this->email->fresh()->classified_at))->not->toBeNull();
});

it('shows why an email could not be classified', function () {
    $this->llm->respondWith(new LLMRefusalException('El modelo rechazó la petición por seguridad.'));

    $this->artisan('ai:classify', ['email' => $this->email->id, '--now' => true])
        ->expectsOutputToContain('refused')
        ->expectsOutputToContain('rechazó la petición por seguridad')
        ->assertFailed();
});

it('queues one email by default', function () {
    Queue::fake();

    $this->artisan('ai:classify', ['email' => $this->email->id])->assertSuccessful();

    Queue::assertPushed(ClassifyEmailJob::class, fn ($job) => $job->emailId === $this->email->id && $job->tenantId === $this->tenant->id);
});

it('queues every ingested email still unclassified', function () {
    Queue::fake();
    $other = Tenant::factory()->create();
    inTenant($other, fn () => Email::factory()->create()->markIngested());
    inTenant($other, fn () => Email::factory()->create());   // a medio ingerir: no
    $classified = inTenant($other, function () {
        $email = Email::factory()->create();
        $email->markIngested();
        $email->forceFill(['classified_at' => now()])->save();

        return $email;
    });

    $this->artisan('ai:classify', ['--pending' => true])
        ->expectsOutput('Emails encolados para clasificar: 2')
        ->assertSuccessful();

    Queue::assertNotPushed(ClassifyEmailJob::class, fn ($job) => $job->emailId === $classified->id);
});

it('fails for an unknown email', function () {
    $this->artisan('ai:classify', ['email' => 999999])->assertFailed();
});
