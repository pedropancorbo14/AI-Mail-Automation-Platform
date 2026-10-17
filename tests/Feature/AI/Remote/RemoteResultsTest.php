<?php

use App\AI\Agent\Enums\EmailReplyStatus;
use App\AI\Enums\AiRequestStatus;
use App\AI\Remote\Contracts\EventStream;
use App\AI\Remote\RemoteResultHandler;
use App\Automation\AutomationMode;
use App\Mail\Enums\EmailStatus;
use App\Mail\Enums\MailConnectionStatus;
use App\Mail\Enums\MailProviderType;
use App\Mail\MailProviderFactory;
use App\Models\AiRequest;
use App\Models\Email;
use App\Models\MailConnection;
use App\Models\Tenant;
use App\Tenancy\Scopes\TenantScope;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Fakes\FakeMailProvider;
use Tests\Fakes\InMemoryEventStream;

beforeEach(function () {
    config(['ai.engine' => 'python', 'ai.anthropic.api_key' => '']);
    $this->streams = new InMemoryEventStream();
    app()->instance(EventStream::class, $this->streams);
    $this->mail = new FakeMailProvider();
    app(MailProviderFactory::class)->extend(MailProviderType::Imap, fn () => $this->mail);

    $this->tenant = Tenant::factory()->create(['name' => 'Empresa Demo']);
    $this->tenant->configureAutomation(AutomationMode::Auto);
    $this->email = inTenant($this->tenant, fn () => Email::factory()->create([
        'mail_connection_id' => MailConnection::factory()->create(['email' => 'soporte@empresa.test', 'status' => MailConnectionStatus::Active]),
        'from_email' => 'cliente@cliente.test',
        'subject' => '¿Hacéis envíos a Canarias?',
        'text_body' => 'Hola, ¿enviáis a Tenerife?',
    ]));
    processRemote($this->email); // publicado: en processing esperando a ai-service
});

/** email.processed.v1 del ejemplo compartido, para el email del test. */
function processedEvent(Email $email, array $overrides = [], array $classification = []): array
{
    $event = json_decode(file_get_contents(base_path('contracts/events/examples/valid/email.processed.v1.json')), true);
    $event['event_id'] = (string) Str::uuid();
    $event['tenant_id'] = $email->tenant_id;
    $event['email_id'] = $email->id;
    $event['causation_id'] = $email->fresh()->ai_event_id;
    $event['category'] = 'product_question';
    $event['decision'] = 'auto_send';
    $event['confidence'] = 0.93;
    $event['reasons'] = [];
    $event['draft'] = "Hola:\n\nSí, enviamos a Canarias.\n\nUn saludo,\nEl equipo de Empresa Demo";
    $event['classification'] = array_merge($event['classification'], ['summary' => 'Pregunta por envíos a Canarias.', 'order_numbers' => [], 'customer_name' => null], $classification);

    return array_merge($event, $overrides);
}

function failedEvent(Email $email, array $overrides = []): array
{
    $event = json_decode(file_get_contents(base_path('contracts/events/examples/valid/email.processing_failed.v1.json')), true);

    return array_merge($event, ['event_id' => (string) Str::uuid(), 'tenant_id' => $email->tenant_id, 'email_id' => $email->id, 'causation_id' => null], $overrides);
}

function deliverResult(array $event, string $type = 'email.processed'): void
{
    test()->streams->publish("{$type}.v1", ['event_id' => $event['event_id'], 'event_type' => $type, 'payload' => json_encode($event)]);
    test()->artisan('ai:consume-results --once')->assertSuccessful();
}

function resultMetric(string $result): float
{
    return metricSample('ai_remote_results_total', ['event' => 'processed', 'result' => $result]) ?? 0.0;
}

it('applies a safe result: classification, AI requests with cost and automatic send', function () {
    deliverResult(processedEvent($this->email));

    $email = $this->email->fresh();
    expect($email->status)->toBe(EmailStatus::Sent) // cola sync: ya enviado
        ->and(remoteStatusPath($email))->toBe(['processing', 'ai_review', 'approved', 'sent'])
        ->and($email->intent->value)->toBe('product_question')
        ->and($email->summary)->toBe('Pregunta por envíos a Canarias.')
        ->and($this->mail->sent)->toHaveCount(1)
        ->and($this->mail->sent[0]->textBody)->toContain('enviamos a Canarias')
        ->and($this->mail->sent[0]->autoSubmitted)->toBeTrue();

    $requests = inTenant($this->tenant, fn () => AiRequest::query()->orderBy('id')->get());
    expect($requests)->toHaveCount(2)
        ->and($requests->pluck('purpose')->all())->toBe(['ai_service.classify', 'ai_service.draft'])
        ->and($requests[0]->cost_usd)->toBe('0.011020') // 1960/159 tokens a precio de Opus 5.5 (config/ai.php)
        ->and($requests[0]->response)->toMatchArray(['source' => 'ai-service', 'prompt' => 'email_classification@1'])
        ->and($requests[0]->email_id)->toBe($email->id)
        ->and($requests[0]->status)->toBe(AiRequestStatus::Success);

    $reply = inTenant($this->tenant, fn () => $email->replies()->sole());
    expect($reply->status)->toBe(EmailReplyStatus::Sent)
        ->and($reply->sent_automatically)->toBeTrue()
        ->and($reply->agent_run_id)->not->toBeNull() // la ejecución de ai-service queda registrada
        ->and(resultMetric('applied'))->toBe(1.0)
        ->and($this->streams->pending['email.processed.v1'] ?? [])->toBe([]); // confirmado
});

it('keeps the last word in Laravel: a company in draft mode never auto-sends', function () {
    $this->tenant->configureAutomation(AutomationMode::Draft);

    deliverResult(processedEvent($this->email));

    $email = $this->email->fresh();
    expect($email->status)->toBe(EmailStatus::HumanReview)
        ->and($this->mail->sent)->toBe([])
        ->and(inTenant($this->tenant, fn () => $email->replies()->sole()->status))->toBe(EmailReplyStatus::Draft);
});

it('sends review decisions to a person with the reasons and the proposal', function () {
    deliverResult(processedEvent($this->email, [
        'decision' => 'human_review',
        'confidence' => 0.6,
        'reasons' => ['La confianza en la respuesta (0,60) es menor que el mínimo (0,85).'],
    ]));

    $email = $this->email->fresh();
    $reply = inTenant($this->tenant, fn () => $email->replies()->sole());
    expect($email->status)->toBe(EmailStatus::HumanReview)
        ->and($reply->needs_human)->toBeTrue()
        ->and($reply->needs_human_reason)->toContain('0,60')
        ->and(lastStatusReasonOf($email))->toContain('0,60')
        ->and($this->mail->sent)->toBe([]);
});

it('ignores spam with high confidence without a reply', function () {
    deliverResult(processedEvent($this->email, ['category' => 'spam', 'draft' => '', 'confidence' => 0, 'decision' => 'human_review', 'reasons' => ['No necesita respuesta (spam).']], ['confidence' => 0.97]));

    expect($this->email->fresh()->status)->toBe(EmailStatus::Ignored)
        ->and(inTenant($this->tenant, fn () => $this->email->replies()->count()))->toBe(0);
});

it('escalates manipulation attempts to a person without a proposal', function () {
    deliverResult(processedEvent($this->email, [
        'category' => 'other', 'draft' => '', 'confidence' => 0, 'decision' => 'escalate',
        'reasons' => ['Posible intento de manipular a la IA: no se propone respuesta.'],
    ], ['injection_suspected' => true, 'needs_human' => true]));

    $email = $this->email->fresh();
    expect($email->status)->toBe(EmailStatus::HumanReview)
        ->and($email->injection_suspected)->toBeTrue()
        ->and(lastStatusReasonOf($email))->toStartWith('La IA lo escala a una persona: Posible intento');
});

it('applies failures: refusals go to a person and configuration errors to failed', function (string $code, EmailStatus $status) {
    deliverResult(failedEvent($this->email, ['error_code' => $code, 'message' => 'Algo pasó.']), 'email.processing_failed');

    expect($this->email->fresh()->status)->toBe($status);
    $request = inTenant($this->tenant, fn () => AiRequest::sole());
    expect($request->status)->toBe(AiRequestStatus::Refused); // los tokens se facturan igual
})->with([
    'refusal' => ['refusal', EmailStatus::HumanReview],
    'llm_config' => ['llm_config', EmailStatus::Failed],
]);

it('never applies a result whose company is not the email company', function () {
    Log::spy();
    $globex = Tenant::factory()->create();

    deliverResult(processedEvent($this->email, ['tenant_id' => $globex->id]));

    expect($this->email->fresh()->status)->toBe(EmailStatus::Processing)
        ->and(inTenant($this->tenant, fn () => AiRequest::count()))->toBe(0)
        ->and(AiRequest::withoutGlobalScope(TenantScope::class)->count())->toBe(0)
        ->and($this->streams->deadLettered[0]['reason'])->toContain('empresa')
        ->and(resultMetric('tenant_mismatch'))->toBe(1.0);
    Log::shouldHaveReceived('critical')->once();
});

it('sends results that break the contract to the dead letter stream', function () {
    $event = processedEvent($this->email, ['decision' => 'send_now']);

    deliverResult($event);

    expect($this->streams->deadLettered)->toHaveCount(1)
        ->and($this->email->fresh()->status)->toBe(EmailStatus::Processing)
        ->and(resultMetric('invalid'))->toBe(1.0);
});

it('does not apply the same result twice', function () {
    $event = processedEvent($this->email);
    deliverResult($event);
    deliverResult($event); // entrega repetida

    expect($this->mail->sent)->toHaveCount(1)
        ->and(inTenant($this->tenant, fn () => $this->email->replies()->count()))->toBe(1)
        ->and(inTenant($this->tenant, fn () => AiRequest::count()))->toBe(2)
        ->and(resultMetric('stale'))->toBe(1.0);
});

it('acknowledges results of emails deleted in the meantime', function () {
    $event = processedEvent($this->email);
    inTenant($this->tenant, fn () => $this->email->delete());

    deliverResult($event);

    expect(resultMetric('missing'))->toBe(1.0)
        ->and($this->streams->deadLettered)->toBe([]);
});

it('retries unexpected errors and finally dead-letters them', function () {
    config(['ai.remote.max_deliveries' => 2]);
    $handler = Mockery::mock(RemoteResultHandler::class);
    $handler->shouldReceive('handle')->andThrow(new RuntimeException('BD caída'));
    app()->instance(RemoteResultHandler::class, $handler);

    deliverResult(processedEvent($this->email)); // 1ª entrega: queda pendiente
    expect($this->streams->pending['email.processed.v1'])->toHaveCount(1)
        ->and(resultMetric('retry'))->toBe(1.0);

    $this->artisan('ai:consume-results --once'); // 2ª (reclamada): a la cola de errores

    expect($this->streams->deadLettered)->toHaveCount(1)
        ->and($this->streams->pending['email.processed.v1'])->toBe([])
        ->and(metricSample('ai_remote_results_total', ['event' => 'processed', 'result' => 'dead_lettered']))->toBe(1.0);
});

it('counts the cost reported by the service against the daily budget', function () {
    deliverResult(processedEvent($this->email));
    config(['ai.limits.tenant_daily_budget_usd' => 0.01]); // ya se han gastado ~0,02 $

    $next = inTenant($this->tenant, fn () => Email::factory()->create(['from_email' => 'otra@cliente.test']));

    expect(processRemote($next))->toBe(EmailStatus::HumanReview)
        ->and(lastStatusReasonOf($next))->toContain('presupuesto diario');
});

function lastStatusReasonOf(Email $email): ?string
{
    return inTenant($email->tenant, fn () => $email->statusChanges()->get()->last()?->reason);
}

it('records what ai-service did as an agent run with its tool calls (also for the content guard)', function () {
    $event = json_decode(file_get_contents(base_path('contracts/events/examples/valid/email.processed.v1--with-rag.json')), true);
    $event = array_merge($event, [
        'event_id' => (string) Str::uuid(),
        'tenant_id' => $this->email->tenant_id,
        'email_id' => $this->email->id,
        'causation_id' => null,
    ]);

    deliverResult($event);

    $email = $this->email->fresh();
    // La respuesta cita 14,95 € de la documentación: ReplyContentGuard lo encuentra en
    // los resultados de la búsqueda (ai_tool_calls de la ejecución) y deja enviarla sola.
    expect($email->status)->toBe(EmailStatus::Sent)
        ->and($this->mail->sent[0]->textBody)->toContain('14,95 €');

    $reply = inTenant($this->tenant, fn () => $email->replies()->sole());
    $run = inTenant($this->tenant, fn () => App\Models\AiAgentRun::sole());
    $call = inTenant($this->tenant, fn () => App\Models\AiToolCall::with('aiRequest')->sole());

    expect($reply->agent_run_id)->toBe($run->id)
        ->and($reply->sources)->toBe([['type' => 'document', 'reference' => 'Política de envíos, sección «Coste del envío»']])
        ->and($run->status->value)->toBe('completed')
        ->and($run->tool_calls_count)->toBe(1)
        ->and($run->duration_ms)->toBe(4200)
        ->and($run->final_output['source'])->toBe('ai-service')
        ->and($call->tool)->toBe('search_company_documents')
        ->and($call->result['results'][0]['content'])->toContain('14,95 €')
        ->and($call->aiRequest->purpose)->toBe('ai_service.knowledge_search')
        ->and($call->aiRequest->agent_run_id)->toBe($run->id)
        ->and($call->aiRequest->cost_usd)->toBe('0.000000'); // bge-m3 local: coste 0
});

it('lets a reply quote the order data ai-service consulted through MCP', function () {
    $order = ['order_number' => '45821', 'status' => 'shipped', 'status_label' => 'Enviado', 'total' => 89.9, 'currency' => 'EUR',
        'shipment' => ['carrier' => 'SEUR', 'tracking_number' => 'SEU123456789ES']];
    $event = processedEvent($this->email, [
        'category' => 'order_status',
        'draft' => "Hola:\n\nTu pedido nº 45821 (89,90 €) está enviado con SEUR, seguimiento SEU123456789ES.\n\nUn saludo,\nEl equipo de Empresa Demo",
        'sources' => [['type' => 'order', 'reference' => '45821']],
        'tool_calls' => [['tool' => 'get_order', 'arguments' => ['order_number' => '45821'], 'result' => $order,
            'status' => 'success', 'error' => null, 'duration_ms' => 35]],
    ], ['order_numbers' => ['45821']]);

    deliverResult($event);

    // ReplyContentGuard encuentra el pedido y el importe en el resultado de get_order.
    expect($this->email->fresh()->status)->toBe(EmailStatus::Sent);
    $call = inTenant($this->tenant, fn () => App\Models\AiToolCall::sole());
    expect($call->tool)->toBe('get_order')
        ->and($call->result['shipment']['carrier'])->toBe('SEUR');
});

it('does not let a reply quote an order that no tool returned', function () {
    $event = processedEvent($this->email, [
        'category' => 'order_status',
        'draft' => "Hola:\n\nTu pedido nº 99999 está enviado.\n\nUn saludo,\nEl equipo de Empresa Demo",
        'tool_calls' => [['tool' => 'get_order', 'arguments' => ['order_number' => '45821'], 'result' => ['order_number' => '45821'],
            'status' => 'success', 'error' => null, 'duration_ms' => 35]],
    ], ['order_numbers' => ['45821']]);

    deliverResult($event);

    expect($this->email->fresh()->status)->toBe(EmailStatus::HumanReview)
        ->and(lastStatusReasonOf($this->email))->toContain('99999');
});

it('does not let a reply quote amounts that are not in what the AI consulted', function () {
    deliverResult(processedEvent($this->email, ['draft' => "Hola:\n\nEl envío a Canarias cuesta 9,99 €.\n\nUn saludo,\nEl equipo de Empresa Demo"]));

    expect($this->email->fresh()->status)->toBe(EmailStatus::HumanReview)
        ->and(lastStatusReasonOf($this->email))->toContain('9,99');
});
