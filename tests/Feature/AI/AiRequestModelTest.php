<?php

use App\AI\Enums\AiRequestStatus;
use App\Models\AiPromptVersion;
use App\Models\AiRequest;
use App\Models\Email;
use App\Models\Tenant;
use App\Tenancy\Exceptions\MissingTenantException;
use Illuminate\Support\Facades\Schema;

function aiRequestAttributes(array $overrides = []): array
{
    return array_merge([
        'purpose' => 'email_classification',
        'provider' => 'anthropic',
        'requested_model' => 'claude-opus-5-5',
        'model' => 'claude-opus-5-5',
        'input_tokens' => 1200,
        'output_tokens' => 300,
        'cost_usd' => '0.010800',
        'latency_ms' => 850,
        'status' => AiRequestStatus::Success,
        'intent' => 'order_status',
        'confidence' => 0.92,
        'response' => ['intent' => 'order_status'],
    ], $overrides);
}

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
});

it('has the expected columns', function () {
    expect(Schema::hasColumns('ai_requests', [
        'id', 'tenant_id', 'email_id', 'ai_prompt_version_id', 'purpose', 'provider', 'requested_model',
        'model', 'used_fallback', 'input_tokens', 'output_tokens', 'cache_write_tokens', 'cache_read_tokens',
        'cost_usd', 'latency_ms', 'status', 'stop_reason', 'error', 'provider_request_id', 'intent',
        'confidence', 'response', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('stores an ai request of the current company linked to its email and prompt', function () {
    $prompt = AiPromptVersion::create(['key' => 'email_classification', 'version' => 1, 'system_prompt' => 'x', 'content_hash' => str_repeat('a', 64)]);

    $request = inTenant($this->tenant, function () use ($prompt) {
        $email = Email::factory()->create();

        return AiRequest::create(aiRequestAttributes(['email_id' => $email->id, 'ai_prompt_version_id' => $prompt->id]))
            ->fresh()->load('email', 'promptVersion');
    });

    expect($request->tenant_id)->toBe($this->tenant->id)
        ->and($request->status)->toBe(AiRequestStatus::Success)
        ->and($request->cost_usd)->toBe('0.010800')
        ->and($request->confidence)->toBe('0.920')
        ->and($request->response)->toBe(['intent' => 'order_status'])
        ->and($request->used_fallback)->toBeFalse()
        ->and($request->email)->not->toBeNull()
        ->and($request->promptVersion->version)->toBe(1);
});

it('keeps the cost record when the email is deleted', function () {
    $request = inTenant($this->tenant, function () {
        $email = Email::factory()->create();
        $request = AiRequest::create(aiRequestAttributes(['email_id' => $email->id]));
        $email->delete();

        return $request->fresh();
    });

    expect($request->email_id)->toBeNull();
});

it('isolates ai requests by company', function () {
    inTenant($this->tenant, fn () => AiRequest::create(aiRequestAttributes()));

    expect(inTenant(Tenant::factory()->create(), fn () => AiRequest::count()))->toBe(0);

    AiRequest::count();
})->throws(MissingTenantException::class);
