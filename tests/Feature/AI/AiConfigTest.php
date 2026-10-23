<?php

it('has a price for the default model of ai-service', function () {
    $model = config('ai.remote.model');

    expect(config("ai.pricing.{$model}"))->toHaveKeys(['input', 'output', 'cache_write', 'cache_read']);
});

it('has a price for the models recommended for each step', function (string $model) {
    // Sin precio el coste quedaría null y el presupuesto diario no lo contaría.
    expect(config("ai.pricing.{$model}"))->toHaveKeys(['input', 'output', 'cache_write', 'cache_read']);
})->with(['claude-haiku-5-5', 'claude-sonnet-5-5']);

it('has no AI SDK in Laravel: the AI lives in ai-service', function () {
    expect(class_exists('Anthropic\Client'))->toBeFalse()
        ->and(config('ai.anthropic'))->toBeNull()
        ->and(config('ai.engine'))->toBeNull();
});

it('limits the body sent to ai-service', function () {
    expect(config('ai.classification.max_body_chars'))->toBeGreaterThan(0);
});
