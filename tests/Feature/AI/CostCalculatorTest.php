<?php

use App\AI\CostCalculator;
use App\AI\Data\LLMUsage;
use Tests\Fakes\FakeLLMProvider;

it('prices input and output tokens of a model', function () {
    // Opus 5.5: 1200 × $4/M + 300 × $20/M = 0.0048 + 0.006
    expect((new CostCalculator())->forUsage('claude-opus-5-5', new LLMUsage(1200, 300)))->toBe('0.010800');
});

it('prices prompt cache writes and reads', function () {
    // 1000 × $5/M (escritura) + 10000 × $0.20/M (lectura)
    expect((new CostCalculator())->forUsage('claude-opus-5-5', new LLMUsage(0, 0, 1000, 10000)))->toBe('0.007000');
});

it('uses the price of the model that answered', function () {
    $response = FakeLLMProvider::response(model: 'claude-haiku-4-5', usage: new LLMUsage(1_000_000, 0));

    expect((new CostCalculator())->forResponse($response))->toBe('1.000000');
});

it('adds the cost of every model involved in a fallback', function () {
    $response = new \App\AI\Data\LLMResponse(
        provider: 'anthropic', model: 'claude-opus-4-8', text: null, data: null,
        usage: new LLMUsage(1100, 400), stopReason: 'end_turn', latencyMs: 1, usedFallback: true,
        usageByModel: [
            ['model' => 'claude-opus-5-5', 'usage' => new LLMUsage(1000, 100)],  // 0.004 + 0.002
            ['model' => 'claude-opus-4-8', 'usage' => new LLMUsage(100, 300)],   // 0.0005 + 0.0075
        ],
    );

    expect((new CostCalculator())->forResponse($response))->toBe('0.014000');
});

it('returns an unknown cost instead of zero for models without price', function () {
    expect((new CostCalculator())->forUsage('modelo-sin-precio', new LLMUsage(1000, 1000)))->toBeNull();
});

it('costs nothing when no tokens were billed', function () {
    expect((new CostCalculator())->forUsage('claude-opus-5-5', new LLMUsage()))->toBe('0.000000');
});
