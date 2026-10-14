<?php

use App\Models\AiToolCall;
use App\Models\Email;
use App\Models\Tenant;
use App\Tenancy\TenantService;
use App\Tools\Contracts\RequiresSender;
use App\Tools\Enums\ToolCallStatus;
use App\Tools\ToolContext;
use App\Tools\ToolExecutor;
use App\Tools\ToolRegistry;
use App\Tools\ToolResult;
use Tests\Fakes\FakeTool;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    // Herramientas de prueba activadas "por defecto" para todas las empresas.
    config(['ai.tools.default_enabled' => ['echo', 'customer_data', 'get_order', 'broken', 'big', 'whoami']]);
    $this->echo = new FakeTool('echo');
    $this->registry = new ToolRegistry([$this->echo]);
    app()->instance(ToolRegistry::class, $this->registry);
});

function runTool(string $name, array $arguments, ?ToolContext $context = null): ToolResult
{
    return app(ToolExecutor::class)->execute($name, $arguments, $context ?? new ToolContext(test()->tenant));
}

function lastToolCall(): AiToolCall
{
    return inTenant(test()->tenant, fn () => AiToolCall::latest('id')->firstOrFail());
}

it('runs a tool with valid arguments and records the call', function () {
    $email = inTenant($this->tenant, fn () => Email::factory()->create());

    $result = runTool('echo', ['text' => 'hola'], new ToolContext($this->tenant, $email));
    $call = lastToolCall();

    expect($result->ok)->toBeTrue()
        ->and($result->toArray())->toBe(['text' => 'hola'])
        ->and($call->only('tool', 'arguments', 'result', 'error', 'email_id'))->toBe([
            'tool' => 'echo', 'arguments' => ['text' => 'hola'], 'result' => ['text' => 'hola'], 'error' => null, 'email_id' => $email->id,
        ])
        ->and($call->status)->toBe(ToolCallStatus::Success)
        ->and($call->duration_ms)->toBeGreaterThanOrEqual(0)
        ->and($call->tenant_id)->toBe($this->tenant->id);
});

it('rejects unknown tools without running anything', function () {
    $result = runTool('delete_all_customers', []);

    expect($result->toArray())->toBe(['error' => 'La herramienta delete_all_customers no existe.'])
        ->and(lastToolCall()->status)->toBe(ToolCallStatus::Rejected);
});

it('rejects arguments that do not match the schema', function (array $arguments, string $message) {
    $result = runTool('echo', $arguments);

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toStartWith('Argumentos no válidos para echo')->toContain($message)
        ->and($this->echo->calls)->toBe([])
        ->and(lastToolCall()->status)->toBe(ToolCallStatus::Rejected);
})->with([
    'falta el obligatorio' => [[], 'text'],
    'tipo incorrecto' => [['text' => 123], 'string'],
    'argumento inventado' => [['text' => 'x', 'sql' => 'DROP TABLE users'], 'sql'],
]);

it('only allows sender tools while processing an email with sender', function () {
    $tool = new class extends FakeTool implements RequiresSender
    {
        public function __construct()
        {
            parent::__construct('customer_data');
        }
    };
    $this->registry->register($tool);

    $without = runTool('customer_data', ['text' => 'x']);
    $email = inTenant($this->tenant, fn () => Email::factory()->create(['from_email' => 'cliente@cliente.test']));
    $with = runTool('customer_data', ['text' => 'x'], new ToolContext($this->tenant, $email));

    expect($without->error)->toContain('solo puede usarse al procesar un email')
        ->and($with->ok)->toBeTrue()
        ->and($tool->calls)->toHaveCount(1);
});

it('records expected tool failures as errors', function () {
    $this->registry->register(new FakeTool('get_order', handler: fn () => ToolResult::error('No existe ese pedido.')));

    $result = runTool('get_order', ['text' => 'x']);

    expect($result->toArray())->toBe(['error' => 'No existe ese pedido.'])
        ->and(lastToolCall()->only('error', 'result'))->toBe(['error' => 'No existe ese pedido.', 'result' => ['error' => 'No existe ese pedido.']])
        ->and(lastToolCall()->status)->toBe(ToolCallStatus::Error);
});

it('never lets an exception reach the model with internal details', function () {
    $this->registry->register(new FakeTool('broken', handler: fn () => throw new RuntimeException('SQLSTATE[42P01] tabla secreta')));

    $result = runTool('broken', ['text' => 'x']);

    expect($result->error)->toBe('Error interno al ejecutar broken. Inténtalo más tarde o deriva el caso a una persona.')
        ->and(json_encode(lastToolCall()->toArray()))->not->toContain('SQLSTATE');
});

it('cuts results that are too big', function () {
    config(['ai.tools.max_result_chars' => 100]);
    $this->registry->register(new FakeTool('big', handler: fn () => ToolResult::success(['text' => str_repeat('ñ', 500)])));

    $result = runTool('big', ['text' => 'x']);

    expect($result->toArray()['truncated'])->toBeTrue()
        ->and(mb_strlen($result->toArray()['partial']))->toBe(100)
        ->and($result->toArray()['note'])->toContain('recortado');
});

it('runs inside the context company and restores the previous one', function () {
    $this->registry->register(new FakeTool('whoami', handler: fn () => ToolResult::success(['tenant' => app(TenantService::class)->id()])));
    $other = Tenant::factory()->create();

    $result = inTenant($other, fn () => runTool('whoami', ['text' => 'x']));

    expect($result->toArray())->toBe(['tenant' => $this->tenant->id])
        ->and(inTenant($other, fn () => AiToolCall::count()))->toBe(0)
        ->and(lastToolCall()->tenant_id)->toBe($this->tenant->id);
});

it('links the call to the ai request that asked for it', function () {
    $request = inTenant($this->tenant, fn () => \App\Models\AiRequest::create([
        'purpose' => 'agent', 'provider' => 'anthropic', 'requested_model' => 'claude-opus-5-5', 'status' => 'success',
    ]));

    runTool('echo', ['text' => 'x'], new ToolContext($this->tenant, aiRequestId: $request->id));

    expect(lastToolCall()->ai_request_id)->toBe($request->id);
});
