<?php

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\AI\Contracts\LLMProvider;
use App\AI\Data\LLMRequest;
use App\AI\Exceptions\LLMConfigurationException;
use App\AI\Exceptions\LLMInvalidResponseException;
use App\AI\Exceptions\LLMRateLimitException;
use App\AI\Exceptions\LLMRefusalException;
use App\AI\Exceptions\LLMUnavailableException;
use App\AI\Providers\Anthropic\AnthropicProvider;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/*
 * AnthropicProvider con el SDK real y un transporte HTTP simulado (Guzzle
 * MockHandler): se prueba lo que se envía y cómo se interpreta cada
 * respuesta de la API, sin llamar nunca a Anthropic.
 */

/** @param  list<Response>  $responses */
function anthropicProvider(array $responses, bool $fallbacks = true): AnthropicProvider
{
    test()->sent = $history = new ArrayObject();
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    $client = new Client(
        apiKey: 'sk-ant-test-secreta',
        requestOptions: RequestOptions::with(maxRetries: 0, transporter: new \GuzzleHttp\Client(['handler' => $stack])),
    );

    return new AnthropicProvider(fn () => $client, 'claude-opus-5-5', $fallbacks);
}

function apiMessage(array $overrides = []): Response
{
    return new Response(200, ['Content-Type' => 'application/json', 'request-id' => 'req_1'], json_encode(array_merge([
        'id' => 'msg_01',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-opus-5-5',
        'content' => [
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'firma'],
            ['type' => 'text', 'text' => '{"intent":"order_status","confidence":0.92}'],
        ],
        'stop_reason' => 'end_turn',
        'stop_sequence' => null,
        'usage' => ['input_tokens' => 1200, 'output_tokens' => 300, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0],
    ], $overrides)));
}

function apiError(int $status, string $type, string $message = 'detalle'): Response
{
    return new Response($status, ['Content-Type' => 'application/json'], json_encode([
        'type' => 'error',
        'error' => ['type' => $type, 'message' => $message],
    ]));
}

function classificationRequest(): LLMRequest
{
    return LLMRequest::single('Clasifica el email.', 'Hola, ¿dónde está mi pedido?', 4096, [
        'type' => 'object',
        'properties' => ['intent' => ['type' => 'string'], 'confidence' => ['type' => 'number']],
        'required' => ['intent', 'confidence'],
        'additionalProperties' => false,
    ], 'low');
}

/** Última petición HTTP enviada a la API. */
function lastSent(): \Psr\Http\Message\RequestInterface
{
    $history = test()->sent;

    return $history[count($history) - 1]['request'];
}

/** Cuerpo JSON de la última petición enviada. */
function sentBody(): array
{
    return json_decode((string) lastSent()->getBody(), true);
}

it('sends the request with structured output, effort and server fallbacks', function () {
    anthropicProvider([apiMessage()])->generate(classificationRequest());

    $request = lastSent();
    $body = sentBody();

    expect((string) $request->getUri())->toStartWith('https://api.anthropic.com/v1/messages')
        ->and($request->getHeaderLine('x-api-key'))->toBe('sk-ant-test-secreta')
        ->and($request->getHeaderLine('anthropic-beta'))->toContain('server-side-fallback-2026-07-01')
        ->and($body['model'])->toBe('claude-opus-5-5')
        ->and($body['max_tokens'])->toBe(4096)
        ->and($body['system'])->toBe('Clasifica el email.')
        ->and($body['messages'])->toBe([['role' => 'user', 'content' => 'Hola, ¿dónde está mi pedido?']])
        ->and($body['output_config']['effort'])->toBe('low')
        ->and($body['output_config']['format']['type'])->toBe('json_schema')
        ->and($body['output_config']['format']['schema']['required'])->toBe(['intent', 'confidence'])
        ->and($body['fallbacks'])->toBe('default')
        ->and($body)->not->toHaveKeys(['thinking', 'temperature']);
});

it('can disable server fallbacks', function () {
    anthropicProvider([apiMessage()], fallbacks: false)->generate(classificationRequest());

    expect(sentBody())->not->toHaveKey('fallbacks')
        ->and(lastSent()->getHeaderLine('anthropic-beta'))->toBe('');
});

it('returns the parsed json, tokens and latency', function () {
    $response = anthropicProvider([apiMessage()])->generate(classificationRequest());

    expect($response->provider)->toBe('anthropic')
        ->and($response->model)->toBe('claude-opus-5-5')
        ->and($response->data)->toBe(['intent' => 'order_status', 'confidence' => 0.92])
        ->and($response->usage->inputTokens)->toBe(1200)
        ->and($response->usage->outputTokens)->toBe(300)
        ->and($response->stopReason)->toBe('end_turn')
        ->and($response->requestId)->toBe('msg_01')
        ->and($response->latencyMs)->toBeGreaterThanOrEqual(0)
        ->and($response->usedFallback)->toBeFalse();
});

it('accounts the tokens of every model when a fallback answered', function () {
    $response = anthropicProvider([apiMessage([
        'model' => 'claude-opus-4-8',
        'usage' => [
            'input_tokens' => 1300, 'output_tokens' => 400, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0,
            'iterations' => [
                ['type' => 'message', 'model' => 'claude-opus-5-5', 'input_tokens' => 1200, 'output_tokens' => 20, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0],
                ['type' => 'fallback_message', 'model' => 'claude-opus-4-8', 'input_tokens' => 100, 'output_tokens' => 380, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0],
            ],
        ],
    ])])->generate(classificationRequest());

    expect($response->usedFallback)->toBeTrue()
        ->and($response->model)->toBe('claude-opus-4-8')
        ->and(array_column($response->usageByModel, 'model'))->toBe(['claude-opus-5-5', 'claude-opus-4-8'])
        ->and($response->usage->inputTokens)->toBe(1300)
        ->and($response->usage->outputTokens)->toBe(400);
});

it('reports a safety refusal with its tokens', function () {
    $provider = anthropicProvider([apiMessage([
        'content' => [],
        'stop_reason' => 'refusal',
        'stop_details' => ['type' => 'refusal', 'category' => 'cyber', 'explanation' => null],
    ])]);

    try {
        $provider->generate(classificationRequest());
        $this->fail('Debería lanzar LLMRefusalException');
    } catch (LLMRefusalException $e) {
        expect($e->getMessage())->toContain('cyber')
            ->and($e->retryable)->toBeFalse()
            ->and($e->response->usage->inputTokens)->toBe(1200);
    }
});

it('rejects answers cut by max_tokens', function () {
    anthropicProvider([apiMessage(['stop_reason' => 'max_tokens'])])->generate(classificationRequest());
})->throws(LLMInvalidResponseException::class, 'max_tokens');

it('rejects answers that are not the expected json', function () {
    anthropicProvider([apiMessage(['content' => [['type' => 'text', 'text' => 'no es json']]])])->generate(classificationRequest());
})->throws(LLMInvalidResponseException::class);

it('translates api errors into retryable or final errors', function (int $status, string $type, string $exception, bool $retryable) {
    try {
        anthropicProvider([apiError($status, $type)])->generate(classificationRequest());
        $this->fail('Debería lanzar una excepción');
    } catch (\App\AI\Exceptions\LLMException $e) {
        expect($e)->toBeInstanceOf($exception)
            ->and($e->retryable)->toBe($retryable)
            ->and($e->getMessage())->not->toContain('sk-ant-test-secreta');
    }
})->with([
    'límite 429' => [429, 'rate_limit_error', LLMRateLimitException::class, true],
    'sobrecarga 529' => [529, 'overloaded_error', LLMUnavailableException::class, true],
    'error 500' => [500, 'api_error', LLMUnavailableException::class, true],
    'clave mala 401' => [401, 'authentication_error', LLMConfigurationException::class, false],
    'sin permiso 403' => [403, 'permission_error', LLMConfigurationException::class, false],
    'modelo inexistente 404' => [404, 'not_found_error', LLMConfigurationException::class, false],
    'petición inválida 400' => [400, 'invalid_request_error', LLMConfigurationException::class, false],
]);

it('reports network failures as retryable', function () {
    anthropicProvider([new \GuzzleHttp\Exception\ConnectException('timeout', new \GuzzleHttp\Psr7\Request('POST', 'https://api.anthropic.com'))])
        ->generate(classificationRequest());
})->throws(LLMUnavailableException::class);

it('does not even build the client without an api key', function () {
    config(['ai.anthropic.api_key' => '']);

    app(LLMProvider::class)->generate(classificationRequest());
})->throws(LLMConfigurationException::class, 'ANTHROPIC_API_KEY');

it('is the configured llm provider', function () {
    expect(app(LLMProvider::class))->toBeInstanceOf(AnthropicProvider::class)
        ->and(app(LLMProvider::class)->name())->toBe('anthropic');
});

// --- Tool calling -----------------------------------------------------------

function toolUseMessage(): Response
{
    return apiMessage([
        'content' => [
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'firma-del-razonamiento'],
            ['type' => 'tool_use', 'id' => 'toolu_01', 'name' => 'get_order', 'input' => ['order_number' => '45821']],
            ['type' => 'tool_use', 'id' => 'toolu_02', 'name' => 'search_company_documents', 'input' => ['query' => 'plazo de entrega']],
        ],
        'stop_reason' => 'tool_use',
    ]);
}

function agentTools(): array
{
    return [[
        'name' => 'get_order',
        'description' => 'Pedido del cliente.',
        'input_schema' => ['type' => 'object', 'properties' => ['order_number' => ['type' => 'string']], 'required' => ['order_number'], 'additionalProperties' => false],
    ]];
}

it('sends tools as strict definitions and returns the requested tool calls', function () {
    $response = anthropicProvider([toolUseMessage()])->generate(new LLMRequest('Agente', [['role' => 'user', 'content' => '¿Mi pedido?']], 4096, tools: agentTools()));

    $tool = sentBody()['tools'][0];
    expect($tool)->toMatchArray(['name' => 'get_order', 'description' => 'Pedido del cliente.', 'strict' => true])
        ->and($tool['input_schema']['required'])->toBe(['order_number'])
        ->and($response->stopReason)->toBe('tool_use')
        ->and($response->wantsTools())->toBeTrue()
        ->and(array_map(fn ($call) => [$call->id, $call->name, $call->input], $response->toolCalls))->toBe([
            ['toolu_01', 'get_order', ['order_number' => '45821']],
            ['toolu_02', 'search_company_documents', ['query' => 'plazo de entrega']],
        ])
        ->and($response->data)->toBeNull();
});

it('does not try to parse json while the model is asking for tools', function () {
    $schema = ['type' => 'object', 'properties' => ['reply' => ['type' => 'string']], 'required' => ['reply'], 'additionalProperties' => false];

    $response = anthropicProvider([toolUseMessage()])->generate(new LLMRequest('Agente', [['role' => 'user', 'content' => 'x']], 4096, $schema, tools: agentTools()));

    expect($response->wantsTools())->toBeTrue();
});

it('sends back the assistant turn untouched and the tool results in one user message', function () {
    $provider = anthropicProvider([toolUseMessage(), apiMessage()]);
    $first = $provider->generate(new LLMRequest('Agente', [['role' => 'user', 'content' => '¿Mi pedido?']], 4096, tools: agentTools()));

    $provider->generate(new LLMRequest('Agente', [
        ['role' => 'user', 'content' => '¿Mi pedido?'],
        ['role' => 'assistant', 'content' => $first->assistantContent],
        ['role' => 'user', 'tool_results' => [
            new \App\AI\Data\LLMToolResult('toolu_01', '{"status":"shipped"}'),
            new \App\AI\Data\LLMToolResult('toolu_02', '{"error":"no disponible"}', isError: true),
        ]],
    ], 4096, tools: agentTools()));

    $messages = sentBody()['messages'];
    expect($messages)->toHaveCount(3)
        ->and($messages[1]['role'])->toBe('assistant')
        ->and($messages[1]['content'][0])->toMatchArray(['type' => 'thinking', 'signature' => 'firma-del-razonamiento'])
        ->and($messages[1]['content'][1])->toMatchArray(['type' => 'tool_use', 'id' => 'toolu_01', 'name' => 'get_order'])
        ->and($messages[2])->toBe(['role' => 'user', 'content' => [
            ['type' => 'tool_result', 'tool_use_id' => 'toolu_01', 'content' => '{"status":"shipped"}', 'is_error' => false],
            ['type' => 'tool_result', 'tool_use_id' => 'toolu_02', 'content' => '{"error":"no disponible"}', 'is_error' => true],
        ]]);
});

it('does not send tools when there are none', function () {
    anthropicProvider([apiMessage()])->generate(classificationRequest());

    expect(sentBody())->not->toHaveKey('tools');
});
