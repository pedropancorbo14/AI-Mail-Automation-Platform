<?php

use App\AI\Agent\Enums\AgentRunStatus;
use App\AI\Agent\Enums\AgentStopReason;
use App\AI\Agent\Enums\EmailReplyStatus;
use App\Models\AiAgentRun;
use App\Models\AiRequest;
use App\Models\Email;
use App\Models\EmailReply;
use App\Models\Tenant;
use App\Tenancy\Exceptions\MissingTenantException;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->email = inTenant($this->tenant, fn () => Email::factory()->create());
});

it('has the expected tables and columns', function () {
    expect(Schema::hasColumns('ai_agent_runs', [
        'id', 'tenant_id', 'email_id', 'ai_prompt_version_id', 'status', 'stop_reason', 'error', 'iterations',
        'tool_calls_count', 'input_tokens', 'output_tokens', 'cost_usd', 'duration_ms', 'final_output', 'started_at', 'finished_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumn('ai_requests', 'agent_run_id'))->toBeTrue()
        ->and(Schema::hasColumns('email_replies', [
            'id', 'tenant_id', 'email_id', 'agent_run_id', 'body', 'language', 'confidence', 'needs_human',
            'needs_human_reason', 'sources', 'pending_actions', 'internal_note', 'status',
        ]))->toBeTrue();
});

it('links an agent run to its steps and its reply', function () {
    [$run, $reply] = inTenant($this->tenant, function () {
        $run = AiAgentRun::create(['email_id' => $this->email->id, 'started_at' => now()]);
        AiRequest::create(['agent_run_id' => $run->id, 'email_id' => $this->email->id, 'purpose' => 'email_agent', 'provider' => 'anthropic', 'requested_model' => 'claude-opus-5-5', 'status' => 'success']);
        $reply = $this->email->replies()->create([
            'agent_run_id' => $run->id, 'body' => 'Hola', 'language' => 'es', 'confidence' => 0.9, 'needs_human' => false,
            'sources' => [['type' => 'order', 'reference' => '45821']], 'pending_actions' => [],
        ]);

        return [$run->fresh()->load('aiRequests', 'reply', 'email'), $reply->fresh()];
    });

    expect($run->status)->toBe(AgentRunStatus::Running)
        ->and($run->aiRequests)->toHaveCount(1)
        ->and($run->reply->id)->toBe($reply->id)
        ->and($run->email->id)->toBe($this->email->id)
        ->and($reply->status)->toBe(EmailReplyStatus::Draft)
        ->and($reply->confidence)->toBe('0.900')
        ->and($reply->sources)->toBe([['type' => 'order', 'reference' => '45821']])
        ->and($reply->tenant_id)->toBe($this->tenant->id);
});

it('stores how a run ended', function () {
    $run = inTenant($this->tenant, function () {
        $run = AiAgentRun::create(['email_id' => $this->email->id]);
        $run->forceFill(['status' => AgentRunStatus::Failed, 'stop_reason' => AgentStopReason::Timeout, 'cost_usd' => '0.031200'])->save();

        return $run->fresh();
    });

    expect($run->stop_reason)->toBe(AgentStopReason::Timeout)
        ->and($run->stop_reason->label())->toBe('Tiempo máximo agotado')
        ->and($run->cost_usd)->toBe('0.031200');
});

it('does not mass assign the reply status', function () {
    expect((new EmailReply(['status' => 'sent', 'body' => 'x']))->status)->toBe(EmailReplyStatus::Draft);
});

it('isolates runs and replies by company', function () {
    inTenant($this->tenant, fn () => AiAgentRun::create(['email_id' => $this->email->id]));

    expect(inTenant(Tenant::factory()->create(), fn () => [AiAgentRun::count(), EmailReply::count()]))->toBe([0, 0]);

    AiAgentRun::count();
})->throws(MissingTenantException::class);
