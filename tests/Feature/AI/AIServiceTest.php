<?php

use App\AI\AIService;
use App\AI\Contracts\LLMProvider;
use App\AI\Data\LLMUsage;
use App\AI\Enums\AiRequestStatus;
use App\AI\Exceptions\LLMRateLimitException;
use App\AI\Exceptions\LLMRefusalException;
use App\Models\AiPromptVersion;
use App\Models\AiRequest;
use App\Models\Email;
use App\Models\Tenant;
use Tests\Fakes\FakeLLMProvider;
use Tests\Fakes\FakePrompt;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->llm = new FakeLLMProvider();
    app()->instance(LLMProvider::class, $this->llm);
});

/** Ejecuta el servicio dentro de la empresa de prueba. */
function runAi(?Email $email = null, ?FakePrompt $prompt = null)
{
    return inTenant(test()->tenant, fn () => app(AIService::class)->run(
        $prompt ?? new FakePrompt(schema: ['type' => 'object'], key: 'email_classification'),
        'Hola, ¿dónde está mi pedido?',
        maxTokens: 4096,
        effort: 'low',
        email: $email,
    ));
}

function lastAiRequest(): AiRequest
{
    return inTenant(test()->tenant, fn () => AiRequest::latest('id')->firstOrFail());
}

it('runs the prompt and records the request with tokens, cost and latency', function () {
    $this->llm->respondWith(FakeLLMProvider::response(['intent' => 'order_status', 'confidence' => 0.92], usage: new LLMUsage(1200, 300), latencyMs: 850));
    $email = inTenant($this->tenant, fn () => Email::factory()->create());

    $result = runAi($email);
    $record = lastAiRequest();

    expect($result->response->data['intent'])->toBe('order_status')
        ->and($result->record->id)->toBe($record->id)
        ->and($record->only('purpose', 'provider', 'requested_model', 'model', 'input_tokens', 'output_tokens', 'cost_usd', 'latency_ms', 'intent', 'confidence', 'email_id'))
        ->toBe([
            'purpose' => 'email_classification',
            'provider' => 'fake',
            'requested_model' => 'claude-opus-5-5',
            'model' => 'claude-opus-5-5',
            'input_tokens' => 1200,
            'output_tokens' => 300,
            'cost_usd' => '0.010800',
            'latency_ms' => 850,
            'intent' => 'order_status',
            'confidence' => '0.920',
            'email_id' => $email->id,
        ])
        ->and($record->status)->toBe(AiRequestStatus::Success)
        ->and($record->response)->toBe(['intent' => 'order_status', 'confidence' => 0.92])
        ->and($record->tenant_id)->toBe($this->tenant->id);
});

it('sends the versioned prompt, schema and effort to the provider', function () {
    $this->llm->respondWith(['intent' => 'other', 'confidence' => 0.5]);

    runAi(prompt: new FakePrompt(3, 'Instrucciones v3', ['type' => 'object', 'required' => ['intent']], 'email_classification'));

    $sent = $this->llm->requests[0];
    expect($sent->system)->toBe('Instrucciones v3')
        ->and($sent->jsonSchema)->toBe(['type' => 'object', 'required' => ['intent']])
        ->and($sent->effort)->toBe('low')
        ->and($sent->maxTokens)->toBe(4096)
        ->and(lastAiRequest()->promptVersion->version)->toBe(3)
        ->and(AiPromptVersion::count())->toBe(1);
});

it('records failed requests and lets the error through', function () {
    $this->llm->respondWith(new LLMRateLimitException('Anthropic: límite de peticiones alcanzado (429).'));

    expect(fn () => runAi())->toThrow(LLMRateLimitException::class);

    $record = lastAiRequest();
    expect($record->status)->toBe(AiRequestStatus::Failed)
        ->and($record->error)->toBe('Anthropic: límite de peticiones alcanzado (429).')
        ->and($record->input_tokens)->toBe(0)
        ->and($record->cost_usd)->toBeNull();
});

it('records refusals with the tokens they billed', function () {
    $refused = FakeLLMProvider::response([], usage: new LLMUsage(1000, 5));
    $this->llm->respondWith(new LLMRefusalException('El modelo rechazó la petición por seguridad (categoría: cyber).', $refused));

    expect(fn () => runAi())->toThrow(LLMRefusalException::class);

    $record = lastAiRequest();
    expect($record->status)->toBe(AiRequestStatus::Refused)
        ->and($record->input_tokens)->toBe(1000)
        ->and($record->cost_usd)->toBe('0.004100');
});

it('hides unexpected internal errors in the record', function () {
    $this->llm->respondWith(new RuntimeException('SQLSTATE detalle interno'));

    expect(fn () => runAi())->toThrow(RuntimeException::class);

    expect(lastAiRequest()->error)->toBe('Error interno al llamar a la IA.');
});

it('keeps confidence between 0 and 1 and ignores non classification answers', function () {
    $this->llm->respondWith(['intent' => 'order_status', 'confidence' => 7], ['summary' => 'Sin intención']);

    runAi();
    expect(lastAiRequest()->confidence)->toBe('1.000');

    runAi();
    expect(lastAiRequest()->only('intent', 'confidence'))->toBe(['intent' => null, 'confidence' => null]);
});

it('is enabled only with an api key', function () {
    expect(AIService::enabled())->toBeFalse();

    config(['ai.anthropic.api_key' => 'sk-ant-x']);

    expect(AIService::enabled())->toBeTrue();
});
