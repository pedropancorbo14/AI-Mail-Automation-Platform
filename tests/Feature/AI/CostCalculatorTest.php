<?php

use App\AI\CostCalculator;
use App\AI\Data\LLMUsage;

it('prices input and output tokens of a model', function () {
    // Opus 5.5: 1200 × $4/M + 300 × $20/M = 0.0048 + 0.006
    expect((new CostCalculator())->forUsage('claude-opus-5-5', new LLMUsage(1200, 300)))->toBe('0.010800');
});

it('prices prompt cache writes and reads', function () {
    // 1000 × $5/M (escritura) + 10000 × $0.20/M (lectura)
    expect((new CostCalculator())->forUsage('claude-opus-5-5', new LLMUsage(0, 0, 1000, 10000)))->toBe('0.007000');
});

it('uses the price of each model', function () {
    expect((new CostCalculator())->forUsage('claude-haiku-4-5', new LLMUsage(1_000_000, 0)))->toBe('1.000000')
        ->and((new CostCalculator())->forUsage('bge-m3', new LLMUsage(5000, 0)))->toBe('0.000000');
});

it('returns an unknown cost instead of zero for models without price', function () {
    expect((new CostCalculator())->forUsage('modelo-sin-precio', new LLMUsage(1000, 1000)))->toBeNull();
});

it('costs nothing when no tokens were billed', function () {
    expect((new CostCalculator())->forUsage('claude-opus-5-5', new LLMUsage()))->toBe('0.000000');
});
