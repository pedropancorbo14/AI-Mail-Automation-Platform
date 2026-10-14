<?php

use App\AI\Agent\AgentResult;
use App\AI\Agent\AgentService;
use App\AI\Agent\Enums\AgentRunStatus;
use App\AI\Agent\Enums\AgentStopReason;
use App\AI\Agent\Enums\EmailReplyStatus;
use App\AI\Contracts\LLMProvider;
use App\AI\Data\LLMToolResult;
use App\AI\Embeddings\EmbeddingProvider;
use App\AI\Exceptions\LLMRefusalException;
use App\AI\Exceptions\LLMUnavailableException;
use App\Models\AiRequest;
use App\Models\AiToolCall;
use App\Models\Email;
use App\Models\Tenant;
use App\Tools\Enums\ToolCallStatus;
use Tests\Fakes\FakeEmbeddingProvider;
use Tests\Fakes\FakeLLMProvider;

beforeEach(function () {
    $this->llm = new FakeLLMProvider();
    app()->instance(LLMProvider::class, $this->llm);
    app()->instance(EmbeddingProvider::class, new FakeEmbeddingProvider());

    $this->tenant = Tenant::factory()->create(['slug' => 'empresa-demo', 'name' => 'Muebles Demo']);
    enableTools($this->tenant, 'get_order', 'get_customer_orders');
    $this->email = inTenant($this->tenant, fn () => Email::factory()->create([
        'from_email' => 'cliente@cliente.test',
        'subject' => '¿Dónde está mi pedido #45821?',
        'text_body' => 'Hola, ¿cuándo me llega el pedido 45821?',
    ]));
});

function runAgent(?Email $email = null, ?Tenant $tenant = null): AgentResult
{
    return inTenant($tenant ?? test()->tenant, fn () => app(AgentService::class)->run($email ?? test()->email));
}

/** Resultados de herramientas enviados al modelo en la petición $n (0 = primera). */
function sentToolResults(int $n): array
{
    return collect(test()->llm->requests[$n]->messages)->last()['tool_results'];
}

it('uses the tools and leaves a reply proposal with the whole trace', function () {
    $this->llm->respondWith(FakeLLMProvider::toolUse([['get_order', ['order_number' => '45821']]]), agentReplyData());

    $result = runAgent();
    $run = $result->run;

    expect($run->status)->toBe(AgentRunStatus::Completed)
        ->and($run->stop_reason)->toBe(AgentStopReason::EndTurn)
        ->and($run->iterations)->toBe(2)
        ->and($run->tool_calls_count)->toBe(1)
        ->and($run->input_tokens)->toBe(1500 + 1200)
        ->and((float) $run->cost_usd)->toBeGreaterThan(0)
        ->and($run->final_output['reply_body'])->toContain('SEU123456789ES')
        ->and($run->finished_at)->not->toBeNull();

    $reply = $result->reply;
    expect($reply->status)->toBe(EmailReplyStatus::Draft)
        ->and($reply->email_id)->toBe($this->email->id)
        ->and($reply->agent_run_id)->toBe($run->id)
        ->and($reply->body)->toContain('SEU123456789ES')
        ->and($reply->confidence)->toBe('0.900')
        ->and($reply->sources)->toBe([['type' => 'order', 'reference' => '45821']]);

    // Auditoría: un ai_request por paso, la herramienta enlazada al paso que la pidió.
    [$steps, $call] = inTenant($this->tenant, fn () => [AiRequest::where('agent_run_id', $run->id)->orderBy('id')->get(), AiToolCall::sole()]);
    expect($steps)->toHaveCount(2)
        ->and($steps[0]->response)->toBe(['tool_calls' => [['name' => 'get_order', 'input' => ['order_number' => '45821']]]])
        ->and($call->ai_request_id)->toBe($steps[0]->id)
        ->and($call->email_id)->toBe($this->email->id)
        ->and($call->status)->toBe(ToolCallStatus::Success);
});

it('gives the model the tool result and keeps the conversation append-only', function () {
    $this->llm->respondWith(
        FakeLLMProvider::toolUse([['get_order', ['order_number' => '45821']]]),
        FakeLLMProvider::toolUse([['search_company_documents', ['query' => 'plazos de entrega']]]),
        agentReplyData(),
    );

    runAgent();

    [$first, $second, $third] = $this->llm->requests;
    expect($second->messages)->toHaveCount(3)
        ->and(array_slice($second->messages, 0, 1))->toBe($first->messages)
        ->and(array_slice($third->messages, 0, 3))->toBe($second->messages)
        ->and($second->messages[1])->toBe(['role' => 'assistant', 'content' => ['fake_turn' => ['toolu_fake_1_get_order']]])
        ->and($first->system)->toBe($third->system)
        ->and($first->tools)->toBe($third->tools);

    /** @var LLMToolResult $result */
    $result = sentToolResults(1)[0];
    expect($result->toolCallId)->toBe('toolu_fake_1_get_order')
        ->and($result->isError)->toBeFalse()
        ->and(json_decode($result->content, true)['shipment']['tracking_number'])->toBe('SEU123456789ES');
});

it('answers several tool calls of one turn in a single message', function () {
    $this->llm->respondWith(
        FakeLLMProvider::toolUse([['get_order', ['order_number' => '45821']], ['get_order', ['order_number' => '76540']]]),
        agentReplyData(),
    );

    runAgent();

    $results = sentToolResults(1);
    expect($results)->toHaveCount(2)
        ->and($results[0]->isError)->toBeFalse()
        // 76540 es de otro cliente: la herramienta no lo enseña y el modelo lo ve como error.
        ->and($results[1]->isError)->toBeTrue()
        ->and(json_decode($results[1]->content, true))->toBe(['error' => 'No hay ningún pedido con ese número asociado a este cliente.']);
});

it('only offers the tools of the company', function () {
    $clinic = Tenant::factory()->create(['slug' => 'clinica']);
    $email = inTenant($clinic, fn () => Email::factory()->create());
    $this->llm->respondWith(agentReplyData());

    runAgent($email, $clinic);

    expect(array_column($this->llm->requests[0]->tools, 'name'))->toBe(['search_company_documents'])
        ->and(array_column(test()->llm->requests[0]->tools, 'name'))->not->toContain('get_order');
});

it('sends the email and its context in the first message', function () {
    $this->llm->respondWith(agentReplyData());

    runAgent();

    $first = $this->llm->requests[0];
    expect($first->messages[0]['content'])->toContain('Empresa: Muebles Demo')->toContain('<email>')
        ->and($first->effort)->toBe('medium')
        ->and($first->jsonSchema['required'])->toContain('reply_body');
});

it('stops at the limits without a proposal', function (string $limit, mixed $value, AgentStopReason $reason, int $calls) {
    config(["ai.agent.{$limit}" => $value]);
    $this->llm->respondWith(...array_fill(0, 10, FakeLLMProvider::toolUse([['get_order', ['order_number' => '45821']]])));

    $result = runAgent();

    expect($result->hasReply())->toBeFalse()
        ->and($result->run->status)->toBe(AgentRunStatus::Failed)
        ->and($result->run->stop_reason)->toBe($reason)
        ->and($result->run->error)->toBe($reason->label())
        ->and($this->llm->requests)->toHaveCount($calls);
})->with([
    'pasos' => ['max_iterations', 3, AgentStopReason::MaxIterations, 3],
    'herramientas' => ['max_tool_calls', 2, AgentStopReason::MaxToolCalls, 3],
    'gasto' => ['max_cost_usd', 0.001, AgentStopReason::Budget, 1],
    'tiempo' => ['timeout_seconds', -1, AgentStopReason::Timeout, 0],
]);

it('treats a safety refusal as a case for a person, without retrying', function () {
    $this->llm->respondWith(new LLMRefusalException('El modelo rechazó la petición por seguridad.'));

    $result = runAgent();

    expect($result->hasReply())->toBeFalse()
        ->and($result->run->stop_reason)->toBe(AgentStopReason::Refusal);
});

it('lets temporary errors through for the queue to retry, leaving the run recorded', function () {
    $this->llm->respondWith(new LLMUnavailableException('Anthropic no está disponible (529).'));

    expect(fn () => runAgent())->toThrow(LLMUnavailableException::class);

    $run = inTenant($this->tenant, fn () => \App\Models\AiAgentRun::sole());
    expect($run->status)->toBe(AgentRunStatus::Failed)
        ->and($run->stop_reason)->toBe(AgentStopReason::Error)
        ->and($run->error)->toContain('529');
});

it('does not save an invalid final answer', function () {
    $this->llm->respondWith(agentReplyData(['reply_body' => '']));

    $result = runAgent();

    expect($result->hasReply())->toBeFalse()
        ->and($result->run->stop_reason)->toBe(AgentStopReason::Error)
        ->and($result->run->error)->toContain('no ha devuelto el texto');
});

it('must run inside the email company', function () {
    app(AgentService::class)->run($this->email);
})->throws(LogicException::class);
