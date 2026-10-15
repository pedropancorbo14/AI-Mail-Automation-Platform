<?php

use App\AI\Contracts\LLMProvider;
use App\AI\Embeddings\EmbeddingInputType;
use App\AI\Embeddings\EmbeddingProvider;
use App\AI\Embeddings\EmbeddingService;
use App\AI\Exceptions\LLMUnavailableException;
use App\Automation\AutomationMode;
use App\Automation\EmailProcessor;
use App\Mail\Enums\MailConnectionStatus;
use App\Mail\Enums\MailProviderType;
use App\Mail\MailProviderFactory;
use App\Models\AiRequest;
use App\Models\Email;
use App\Models\MailConnection;
use App\Models\Tenant;
use Tests\Fakes\FakeEmbeddingProvider;
use Tests\Fakes\FakeLLMProvider;
use Tests\Fakes\FakeMailProvider;

beforeEach(function () {
    config(['ai.anthropic.api_key' => 'sk-ant-x']);
    $this->llm = new FakeLLMProvider();
    app()->instance(LLMProvider::class, $this->llm);
    app()->instance(EmbeddingProvider::class, new FakeEmbeddingProvider());
    app(MailProviderFactory::class)->extend(MailProviderType::Imap, fn () => new FakeMailProvider());

    $this->tenant = Tenant::factory()->create(['slug' => 'empresa-demo']);
    $this->tenant->configureAutomation(AutomationMode::Auto);
    enableTools($this->tenant, 'get_order');
    $this->email = inTenant($this->tenant, fn () => Email::factory()->create([
        'mail_connection_id' => MailConnection::factory()->create(['email' => 'soporte@empresa.test', 'status' => MailConnectionStatus::Active]),
        'from_email' => 'cliente@cliente.test',
        'subject' => '¿Dónde está mi pedido #45821?',
        'text_body' => 'Hola',
    ]));
});

function aiMetricsProcess(): void
{
    inTenant(test()->tenant, fn () => app(EmailProcessor::class)->process(test()->email->fresh()));
}

it('counts every AI request with the same tokens and cost as ai_requests', function () {
    $this->llm->respondWith(classificationData(), FakeLLMProvider::toolUse([['get_order', ['order_number' => '45821']]]), agentReplyData());

    aiMetricsProcess();

    $requests = inTenant($this->tenant, fn () => AiRequest::all());
    $labels = ['tenant' => 'empresa-demo', 'model' => 'claude-opus-5-5'];

    expect($requests)->toHaveCount(3)
        ->and(metricSample('ai_requests_total', ['tenant' => 'empresa-demo', 'provider' => 'fake', 'model' => 'claude-opus-5-5', 'purpose' => 'email_classification', 'status' => 'success']))->toBe(1.0)
        ->and(metricSample('ai_requests_total', ['purpose' => 'email_agent', 'status' => 'success']))->toBe(2.0)
        ->and(metricSample('ai_tokens_total', $labels + ['type' => 'input']))->toBe((float) $requests->sum('input_tokens'))
        ->and(metricSample('ai_tokens_total', $labels + ['type' => 'output']))->toBe((float) $requests->sum('output_tokens'))
        ->and(round(metricSample('ai_cost_usd_total', $labels), 6))->toBe(round($requests->sum(fn ($r) => (float) $r->cost_usd), 6))
        ->and(metricSample('ai_request_duration_seconds_count', ['purpose' => 'email_agent']))->toBe(2.0);
});

it('records the classification intent and confidence', function () {
    $this->llm->respondWith(classificationData(['intent' => 'order_status', 'confidence' => 0.92]), FakeLLMProvider::toolUse([['get_order', ['order_number' => '45821']]]), agentReplyData());

    aiMetricsProcess();

    expect(metricSample('ai_classifications_total', ['tenant' => 'empresa-demo', 'intent' => 'order_status', 'needs_human' => false]))->toBe(1.0)
        ->and(metricSample('ai_classification_confidence_bucket', ['intent' => 'order_status', 'le' => '0.9']))->toBe(0.0)
        ->and(metricSample('ai_classification_confidence_bucket', ['intent' => 'order_status', 'le' => '0.95']))->toBe(1.0);
});

it('records the tools the AI used and how the agent run ended', function () {
    $this->llm->respondWith(
        classificationData(),
        FakeLLMProvider::toolUse([['get_order', ['order_number' => '45821']], ['get_customer_orders', []]]), // la segunda no está activada
        agentReplyData(),
    );

    aiMetricsProcess();

    expect(metricSample('ai_tool_calls_total', ['tenant' => 'empresa-demo', 'tool' => 'get_order', 'status' => 'success']))->toBe(1.0)
        ->and(metricSample('ai_tool_calls_total', ['tool' => 'get_customer_orders', 'status' => 'rejected']))->toBe(1.0)
        ->and(metricSample('ai_tool_call_duration_seconds_count', ['tool' => 'get_order']))->toBe(1.0)
        ->and(metricSample('ai_agent_runs_total', ['tenant' => 'empresa-demo', 'status' => 'completed', 'stop_reason' => 'end_turn']))->toBe(1.0)
        ->and(metricSample('ai_agent_run_duration_seconds_count', ['stop_reason' => 'end_turn']))->toBe(1.0);
});

it('counts failed requests and failed agent runs', function () {
    $this->llm->respondWith(classificationData(), new LLMUnavailableException('Anthropic no responde'));

    expect(fn () => aiMetricsProcess())->toThrow(LLMUnavailableException::class)
        ->and(metricSample('ai_requests_total', ['purpose' => 'email_agent', 'status' => 'failed']))->toBe(1.0)
        ->and(metricSample('ai_agent_runs_total', ['status' => 'failed', 'stop_reason' => 'error']))->toBe(1.0);
});

it('counts embeddings as free AI requests', function () {
    inTenant($this->tenant, fn () => app(EmbeddingService::class)->embed(['¿Cómo devuelvo un producto?'], EmbeddingInputType::Query, EmbeddingService::PURPOSE_SEARCH));

    expect(metricSample('ai_requests_total', ['purpose' => 'knowledge_search', 'status' => 'success']))->toBe(1.0)
        ->and(metricSample('ai_tokens_total', ['purpose' => 'knowledge_search', 'type' => 'input']))->toBeGreaterThan(0.0);
});
