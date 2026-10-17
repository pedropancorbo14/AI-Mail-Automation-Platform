<?php

use App\AI\Contracts\LLMProvider;
use App\AI\Embeddings\EmbeddingProvider;
use App\AI\Remote\Contracts\EventStream;
use App\AI\Remote\EventContract;
use App\AI\Remote\ShadowPublisher;
use App\Automation\AutomationMode;
use App\Automation\EmailProcessor;
use App\Mail\Enums\EmailStatus;
use App\Mail\Enums\MailConnectionStatus;
use App\Mail\Enums\MailProviderType;
use App\Mail\MailProviderFactory;
use App\Models\AiRequest;
use App\Models\AiShadowRun;
use App\Models\Email;
use App\Models\MailConnection;
use App\Models\Tenant;
use App\Security\AiSpendGuard;
use App\Tenancy\Scopes\TenantScope;
use Illuminate\Support\Str;
use Tests\Fakes\FakeEmbeddingProvider;
use Tests\Fakes\FakeMailProvider;
use Tests\Fakes\InMemoryEventStream;

/*
 * Modo sombra (AI-Service, Fase 5): con el motor de Laravel activo, una muestra
 * de emails se publica también a ai-service sin efectos, para comparar.
 */

beforeEach(function () {
    config([
        'ai.engine' => 'laravel',
        'ai.shadow.enabled' => true,
        'ai.shadow.sample_rate' => 1.0,
        // El motor de Laravel con la IA simulada (sin API ni coste real).
        'ai.provider' => 'simulated',
        'ai.simulated.delay_ms' => 0,
        'ai.anthropic.api_key' => '',
    ]);
    app()->forgetInstance(LLMProvider::class);
    app()->instance(EmbeddingProvider::class, new FakeEmbeddingProvider());
    $this->streams = new InMemoryEventStream();
    app()->instance(EventStream::class, $this->streams);
    $this->mail = new FakeMailProvider();
    app(MailProviderFactory::class)->extend(MailProviderType::Imap, fn () => $this->mail);

    $this->tenant = Tenant::factory()->create(['slug' => 'empresa-demo']);
    $this->tenant->configureAutomation(AutomationMode::Draft);
    $this->email = shadowEmail($this->tenant);
});

function shadowEmail(Tenant $tenant, array $overrides = []): Email
{
    return inTenant($tenant, fn () => Email::factory()->create(array_merge([
        'mail_connection_id' => MailConnection::factory()->create(['status' => MailConnectionStatus::Active]),
        'from_email' => 'cliente@cliente.test',
        'subject' => '¿Hacéis envíos a Canarias?',
        'text_body' => 'Hola, ¿enviáis a Tenerife?',
    ], $overrides)));
}

/** email.processed.v1 en sombra para la copia publicada de $email. */
function shadowResult(Email $email, array $overrides = []): array
{
    $event = json_decode(file_get_contents(base_path('contracts/events/examples/valid/email.processed.v1--shadow.json')), true);
    $run = AiShadowRun::withoutGlobalScope(TenantScope::class)->where('email_id', $email->id)->sole();

    return array_merge($event, [
        'event_id' => (string) Str::uuid(),
        'tenant_id' => $email->tenant_id,
        'email_id' => $email->id,
        'causation_id' => $run->event_id,
    ], $overrides);
}

function deliverShadow(array $event, string $type = 'email.processed'): void
{
    test()->streams->publish("{$type}.v1", ['event_id' => $event['event_id'], 'event_type' => $type, 'payload' => json_encode($event)]);
    test()->artisan('ai:consume-results --once')->assertSuccessful();
}

it('publishes a shadow copy while the Laravel engine processes the email as usual', function () {
    $status = inTenant($this->tenant, fn () => app(EmailProcessor::class)->process($this->email->fresh()));

    [$event] = $this->streams->payloads('email.received.v1');
    app(EventContract::class)->validate('email.received.v1', $event);
    $email = $this->email->fresh();
    $run = inTenant($this->tenant, fn () => AiShadowRun::sole());

    // El motor de Laravel hace su trabajo: la sombra no cambia nada del email.
    expect($status)->toBe(EmailStatus::HumanReview)
        ->and(inTenant($this->tenant, fn () => AiRequest::count()))->toBeGreaterThan(0)
        ->and($event['mode'])->toBe('shadow')
        ->and($event['email_id'])->toBe($email->id)
        ->and($run->event_id)->toBe($event['event_id'])
        ->and($run->status)->toBe(AiShadowRun::PENDING)
        // Los campos del motor remoto son del email real: no se tocan.
        ->and($email->ai_event_id)->toBeNull()
        ->and($email->ai_requested_at)->toBeNull();
});

it('samples emails deterministically and never twice', function () {
    config(['ai.shadow.sample_rate' => 0.0]);
    expect(app(ShadowPublisher::class)->maybePublish($this->email))->toBeNull();

    config(['ai.shadow.sample_rate' => 0.5]);
    $emails = collect(range(1, 40))->map(fn () => shadowEmail($this->tenant));
    $picked = $emails->filter(fn (Email $email) => ShadowPublisher::sampled($email));
    expect($picked->count())->toBeGreaterThan(5)->toBeLessThan(35)
        ->and($emails->filter(fn (Email $email) => ShadowPublisher::sampled($email))->pluck('id'))->toEqual($picked->pluck('id'));

    config(['ai.shadow.sample_rate' => 1.0]);
    inTenant($this->tenant, fn () => app(ShadowPublisher::class)->maybePublish($this->email));
    inTenant($this->tenant, fn () => app(ShadowPublisher::class)->maybePublish($this->email));
    expect(inTenant($this->tenant, fn () => AiShadowRun::where('email_id', $this->email->id)->count()))->toBe(1);
});

it('does nothing when disabled or when the Python engine is the live one', function () {
    config(['ai.shadow.enabled' => false]);
    expect(ShadowPublisher::enabled())->toBeFalse();

    config(['ai.shadow.enabled' => true, 'ai.engine' => 'python']);
    expect(ShadowPublisher::enabled())->toBeFalse()
        ->and(inTenant($this->tenant, fn () => app(ShadowPublisher::class)->maybePublish($this->email)))->toBeNull();
});

it('stores the shadow result with its cost and never touches the email', function () {
    inTenant($this->tenant, fn () => app(EmailProcessor::class)->process($this->email->fresh()));
    $before = $this->email->fresh()->only(['status', 'intent', 'classification_ai_request_id']);
    $requests = inTenant($this->tenant, fn () => AiRequest::count());

    deliverShadow(shadowResult($this->email, ['specialists' => ['orders', 'billing']]));

    $run = inTenant($this->tenant, fn () => AiShadowRun::sole());
    expect($run->status)->toBe(AiShadowRun::COMPLETED)
        ->and($run->decision)->toBe('human_review')
        ->and($run->category)->toBe('order_status')
        ->and($run->specialists)->toBe(['orders', 'billing'])
        ->and($run->input_tokens)->toBeGreaterThan(0)
        ->and((float) $run->cost_usd)->toBeGreaterThan(0)
        ->and($this->email->fresh()->only(['status', 'intent', 'classification_ai_request_id']))->toBe($before)
        ->and(inTenant($this->tenant, fn () => AiRequest::count()))->toBe($requests)
        ->and($this->mail->sent)->toBe([]);

    // Entrega repetida: no cambia nada.
    deliverShadow(shadowResult($this->email, ['decision' => 'escalate']));
    expect(inTenant($this->tenant, fn () => AiShadowRun::sole())->decision)->toBe('human_review');
});

it('stores a failed shadow copy', function () {
    inTenant($this->tenant, fn () => app(ShadowPublisher::class)->maybePublish($this->email));
    $failed = json_decode(file_get_contents(base_path('contracts/events/examples/valid/email.processing_failed.v1--shadow.json')), true);
    $run = inTenant($this->tenant, fn () => AiShadowRun::sole());

    deliverShadow(array_merge($failed, [
        'event_id' => (string) Str::uuid(),
        'tenant_id' => $this->tenant->id,
        'email_id' => $this->email->id,
        'causation_id' => $run->event_id,
    ]), 'email.processing_failed');

    $run->refresh();
    expect($run->status)->toBe(AiShadowRun::FAILED)
        ->and($run->error_code)->toBe($failed['error_code']);
});

it('refuses a shadow result for another tenant or email', function () {
    inTenant($this->tenant, fn () => app(ShadowPublisher::class)->maybePublish($this->email));
    $other = Tenant::factory()->create();

    deliverShadow(shadowResult($this->email, ['tenant_id' => $other->id]));

    expect(inTenant($this->tenant, fn () => AiShadowRun::sole())->status)->toBe(AiShadowRun::PENDING)
        ->and($this->streams->deadLettered)->toHaveCount(1);
});

it('counts the shadow cost against the daily budget of the tenant', function () {
    inTenant($this->tenant, fn () => app(ShadowPublisher::class)->maybePublish($this->email));
    deliverShadow(shadowResult($this->email));

    $shadowCost = (float) inTenant($this->tenant, fn () => AiShadowRun::sole()->cost_usd);
    expect(inTenant($this->tenant, fn () => AiSpendGuard::spentToday()))->toBe($shadowCost);
});

it('deletes the shadow copy with the email (retention)', function () {
    inTenant($this->tenant, fn () => app(ShadowPublisher::class)->maybePublish($this->email));

    inTenant($this->tenant, fn () => $this->email->delete());

    expect(AiShadowRun::withoutGlobalScope(TenantScope::class)->count())->toBe(0);
});

/** Email que el motor de Laravel ya decidió (pasó por la IA y quedó en $status). */
function decidedEmail(Tenant $tenant, string $intent, EmailStatus $status, ?bool $needsHuman = null): Email
{
    return inTenant($tenant, function () use ($tenant, $intent, $status, $needsHuman) {
        $email = shadowEmail($tenant);
        $email->forceFill(['intent' => $intent, 'intent_confidence' => 0.9, 'classified_at' => now()])->save();
        $email->transitionTo(EmailStatus::Processing, 'Procesamiento automático.');
        if ($needsHuman !== null) {
            draftReply($email, ['needs_human' => $needsHuman]);
            $email->transitionTo(EmailStatus::AiReview, 'Respuesta propuesta.');
        }
        $email->transitionTo($status, 'Decidido.');

        return $email->fresh();
    });
}

/** Copia en sombra ya respondida por ai-service. */
function answeredShadow(Email $email, array $values): AiShadowRun
{
    return inTenant($email->tenant, function () use ($email, $values) {
        $run = new AiShadowRun();
        $run->forceFill(array_merge([
            'email_id' => $email->id,
            'event_id' => (string) Str::uuid(),
            'requested_at' => now(),
            'status' => AiShadowRun::COMPLETED,
            'completed_at' => now(),
            'confidence' => 0.9,
            'cost_usd' => '0.010000',
            'duration_ms' => 3000,
        ], $values))->save();

        return $run;
    });
}

it('compares once both engines have finished, recommendation against recommendation', function () {
    $same = decidedEmail($this->tenant, 'product_question', EmailStatus::HumanReview, needsHuman: false);
    answeredShadow($same, ['category' => 'product_question', 'decision' => 'auto_send']);
    $different = decidedEmail($this->tenant, 'complaint', EmailStatus::HumanReview, needsHuman: true);
    answeredShadow($different, ['category' => 'billing_issue', 'decision' => 'escalate']);
    $spam = decidedEmail($this->tenant, 'spam', EmailStatus::Ignored);
    answeredShadow($spam, ['category' => 'spam', 'confidence' => 0.97, 'decision' => 'human_review']);
    $waiting = inTenant($this->tenant, fn () => tap(shadowEmail($this->tenant))->transitionTo(EmailStatus::Processing, 'En marcha.'));
    answeredShadow($waiting, ['category' => 'order_status', 'decision' => 'auto_send']);

    $this->artisan('ai:shadow-compare')->expectsOutputToContain('Comparadas: 3')->assertSuccessful();

    $runs = inTenant($this->tenant, fn () => AiShadowRun::get()->keyBy('email_id'));
    // La empresa está en modo borrador, pero se compara lo que recomendó cada motor.
    expect($runs[$same->id]->only(['laravel_decision', 'python_decision', 'intent_match', 'decision_match']))
        ->toBe(['laravel_decision' => 'auto_send', 'python_decision' => 'auto_send', 'intent_match' => true, 'decision_match' => true])
        // escalate cuenta como revisión humana: coincide la decisión, no la intención.
        ->and($runs[$different->id]->only(['python_decision', 'intent_match', 'decision_match']))
        ->toBe(['python_decision' => 'human_review', 'intent_match' => false, 'decision_match' => true])
        // Spam con confianza alta: Laravel lo ignoraría también con el resultado de Python.
        ->and($runs[$spam->id]->only(['laravel_decision', 'python_decision']))->toBe(['laravel_decision' => 'ignore', 'python_decision' => 'ignore'])
        ->and($runs[$same->id]->laravel_duration_ms)->not->toBeNull()
        // Laravel aún no ha decidido: se compara en otra pasada.
        ->and($runs[$waiting->id]->compared_at)->toBeNull()
        ->and(metricSample('ai_shadow_comparisons_total', ['intent_match' => true, 'decision_match' => true]))->toBe(2.0)
        ->and(metricSample('ai_shadow_decisions_total', ['laravel' => 'ignore', 'python' => 'ignore']))->toBe(1.0)
        ->and(metricSample('ai_shadow_cost_usd_total', ['engine' => 'python']))->toBe(0.03);
});

it('gives up on copies ai-service never answered and counts them as its failures', function () {
    $email = decidedEmail($this->tenant, 'order_status', EmailStatus::HumanReview, needsHuman: true);
    inTenant($this->tenant, function () use ($email) {
        $run = new AiShadowRun();
        $run->forceFill(['email_id' => $email->id, 'event_id' => (string) Str::uuid(), 'requested_at' => now()->subDays(2)])->save();
    });

    $this->artisan('ai:shadow-compare')->expectsOutputToContain('Sin respuesta de ai-service: 1')->assertSuccessful();

    $run = inTenant($this->tenant, fn () => AiShadowRun::sole());
    expect($run->status)->toBe(AiShadowRun::FAILED)
        ->and($run->error_code)->toBe('no_response')
        ->and($run->python_decision)->toBe('failed')
        ->and($run->decision_match)->toBeFalse();
});

it('reports agreement, costs and disagreements to the operator', function () {
    $same = decidedEmail($this->tenant, 'product_question', EmailStatus::HumanReview, needsHuman: false);
    answeredShadow($same, ['category' => 'product_question', 'decision' => 'auto_send']);
    $different = decidedEmail($this->tenant, 'order_status', EmailStatus::HumanReview, needsHuman: true);
    answeredShadow($different, ['category' => 'order_status', 'decision' => 'auto_send', 'reasons' => []]);
    $this->artisan('ai:shadow-compare')->assertSuccessful();

    $this->artisan('ai:shadow-report')
        ->expectsOutputToContain('Copias comparadas: 2')
        ->expectsOutputToContain('Misma decisión')
        ->expectsOutputToContain("#{$different->id}")
        ->assertSuccessful();
});

it('is scheduled every five minutes', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('ai:shadow-compare')->assertSuccessful();
});
