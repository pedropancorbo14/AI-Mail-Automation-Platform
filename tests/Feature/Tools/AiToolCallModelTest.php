<?php

use App\AI\Enums\AiRequestStatus;
use App\Models\AiRequest;
use App\Models\AiToolCall;
use App\Models\Email;
use App\Models\Tenant;
use App\Tenancy\Exceptions\MissingTenantException;
use App\Tools\Enums\ToolCallStatus;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
});

function aiRequestFor(Tenant $tenant): AiRequest
{
    return inTenant($tenant, fn () => AiRequest::create([
        'purpose' => 'agent', 'provider' => 'anthropic', 'requested_model' => 'claude-opus-5-5', 'status' => AiRequestStatus::Success,
    ]));
}

it('has the expected columns', function () {
    expect(Schema::hasColumns('ai_tool_calls', [
        'id', 'tenant_id', 'ai_request_id', 'email_id', 'tool', 'arguments', 'result', 'status', 'error', 'duration_ms', 'created_at',
    ]))->toBeTrue();
});

it('stores a tool call linked to its ai request and email', function () {
    $request = aiRequestFor($this->tenant);

    $call = inTenant($this->tenant, function () use ($request) {
        $email = Email::factory()->create();

        return AiToolCall::create([
            'ai_request_id' => $request->id,
            'email_id' => $email->id,
            'tool' => 'get_order',
            'arguments' => ['order_number' => '45821'],
            'result' => ['status' => 'shipped'],
            'status' => ToolCallStatus::Success,
            'duration_ms' => 12,
        ])->fresh()->load('aiRequest', 'email');
    });

    expect($call->tenant_id)->toBe($this->tenant->id)
        ->and($call->status)->toBe(ToolCallStatus::Success)
        ->and($call->arguments)->toBe(['order_number' => '45821'])
        ->and($call->result)->toBe(['status' => 'shipped'])
        ->and($call->aiRequest->id)->toBe($request->id)
        ->and($call->email)->not->toBeNull()
        ->and(inTenant($this->tenant, fn () => $request->toolCalls()->count()))->toBe(1);
});

it('keeps the audit when the ai request or the email are deleted', function () {
    $request = aiRequestFor($this->tenant);
    $call = inTenant($this->tenant, fn () => AiToolCall::create([
        'ai_request_id' => $request->id, 'tool' => 'get_order', 'arguments' => [], 'status' => ToolCallStatus::Rejected, 'error' => 'Argumentos inválidos',
    ]));

    inTenant($this->tenant, fn () => $request->delete());

    expect(inTenant($this->tenant, fn () => $call->fresh()))
        ->ai_request_id->toBeNull()
        ->error->toBe('Argumentos inválidos');
});

it('isolates tool calls by company', function () {
    inTenant($this->tenant, fn () => AiToolCall::create(['tool' => 'x', 'arguments' => [], 'status' => ToolCallStatus::Success]));

    expect(inTenant(Tenant::factory()->create(), fn () => AiToolCall::count()))->toBe(0);

    AiToolCall::count();
})->throws(MissingTenantException::class);
