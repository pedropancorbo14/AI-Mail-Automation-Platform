<?php

use App\AI\Remote\AiServiceClient;
use App\Automation\AutomationMode;
use App\Mail\Enums\MailConnectionStatus;
use App\Mail\Enums\MailProviderType;
use App\Mail\MailProviderFactory;
use App\Models\AiRequest;
use App\Models\Email;
use App\Models\MailConnection;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeMailProvider;

/*
 * Métricas de la IA: salen de lo que Laravel registra de cada resultado de
 * ai-service (ai_requests, ai_tool_calls, ai_agent_runs).
 */

beforeEach(function () {
    app(MailProviderFactory::class)->extend(MailProviderType::Imap, fn () => new FakeMailProvider());

    $this->tenant = Tenant::factory()->create(['slug' => 'empresa-demo']);
    $this->tenant->configureAutomation(AutomationMode::Auto);
    $this->email = inTenant($this->tenant, fn () => Email::factory()->create([
        'mail_connection_id' => MailConnection::factory()->create(['email' => 'soporte@empresa.test', 'status' => MailConnectionStatus::Active]),
        'from_email' => 'cliente@cliente.test',
        'subject' => '¿Hacéis envíos a Canarias?',
        'text_body' => 'Hola',
    ]));
});

it('counts every AI request with the same tokens and cost as ai_requests', function () {
    processWithAi($this->email);

    $requests = inTenant($this->tenant, fn () => AiRequest::all());
    $labels = ['tenant' => 'empresa-demo', 'model' => 'claude-opus-5-5'];

    expect($requests)->toHaveCount(2)
        ->and(metricSample('ai_requests_total', ['tenant' => 'empresa-demo', 'provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'purpose' => 'ai_service.classification', 'status' => 'success']))->toBe(1.0)
        ->and(metricSample('ai_requests_total', ['purpose' => 'ai_service.draft', 'status' => 'success']))->toBe(1.0)
        ->and(metricSample('ai_tokens_total', $labels + ['type' => 'input']))->toBe((float) $requests->sum('input_tokens'))
        ->and(metricSample('ai_tokens_total', $labels + ['type' => 'output']))->toBe((float) $requests->sum('output_tokens'))
        ->and(round(metricSample('ai_cost_usd_total', $labels), 6))->toBe(round($requests->sum(fn ($r) => (float) $r->cost_usd), 6))
        ->and(metricSample('ai_request_duration_seconds_count', ['purpose' => 'ai_service.draft']))->toBe(1.0);
});

it('records the classification intent and confidence', function () {
    processWithAi($this->email, ['category' => 'product_question'], ['confidence' => 0.92]);

    expect(metricSample('ai_classifications_total', ['tenant' => 'empresa-demo', 'intent' => 'product_question', 'needs_human' => false]))->toBe(1.0)
        ->and(metricSample('ai_classification_confidence_bucket', ['intent' => 'product_question', 'le' => '0.9']))->toBe(0.0)
        ->and(metricSample('ai_classification_confidence_bucket', ['intent' => 'product_question', 'le' => '0.95']))->toBe(1.0);
});

it('records the tools the AI used and how the agent run ended', function () {
    processWithAi($this->email, ['decision' => 'human_review', 'tool_calls' => [
        ['tool' => 'get_order', 'arguments' => ['order_number' => '45821'], 'result' => ['order_number' => '45821'], 'status' => 'success', 'error' => null, 'duration_ms' => 35, 'origin' => 'plan'],
        ['tool' => 'get_customer_orders', 'arguments' => new stdClass(), 'result' => null, 'status' => 'rejected', 'error' => 'No está activada.', 'duration_ms' => 0, 'origin' => 'model'],
    ]]);

    expect(metricSample('ai_tool_calls_total', ['tenant' => 'empresa-demo', 'tool' => 'get_order', 'status' => 'success']))->toBe(1.0)
        ->and(metricSample('ai_tool_calls_total', ['tool' => 'get_customer_orders', 'status' => 'rejected']))->toBe(1.0)
        ->and(metricSample('ai_tool_call_duration_seconds_count', ['tool' => 'get_order']))->toBe(1.0)
        ->and(metricSample('ai_agent_runs_total', ['tenant' => 'empresa-demo', 'status' => 'completed', 'stop_reason' => 'end_turn']))->toBe(1.0)
        ->and(metricSample('ai_agent_run_duration_seconds_count', ['stop_reason' => 'end_turn']))->toBe(1.0);
});

it('counts the failed requests of a processing that failed', function () {
    processRemote($this->email);
    applyAiResult(failedEvent($this->email, ['causation_id' => $this->email->fresh()->ai_event_id]), 'email.processing_failed');

    expect(metricSample('ai_requests_total', ['purpose' => 'ai_service.classification', 'status' => 'refused']))->toBe(1.0);
});

it('counts the embeddings of a search in the documentation as free AI requests', function () {
    Http::fake(['ai-service.test/*' => Http::response(['results' => [], 'calls' => [[
        'purpose' => 'ai_service.knowledge_search', 'provider' => 'ollama', 'requested_model' => 'bge-m3', 'model' => 'bge-m3',
        'status' => 'success', 'input_tokens' => 12, 'output_tokens' => 0, 'cache_write_tokens' => 0, 'cache_read_tokens' => 0,
        'latency_ms' => 150, 'stop_reason' => null, 'provider_request_id' => null, 'error' => null, 'prompt' => null,
    ]]])]);

    app(AiServiceClient::class)->searchKnowledge($this->tenant, '¿Cómo devuelvo un producto?');

    expect(metricSample('ai_requests_total', ['purpose' => 'ai_service.knowledge_search', 'status' => 'success']))->toBe(1.0)
        ->and(metricSample('ai_tokens_total', ['purpose' => 'ai_service.knowledge_search', 'type' => 'input']))->toBe(12.0);
});
