<?php

use Anthropic\Client;

it('has a price for the default model', function () {
    $model = config('ai.anthropic.model');

    expect(config("ai.pricing.{$model}"))->toHaveKeys(['input', 'output', 'cache_write', 'cache_read']);
});

it('never has a real api key while testing', function () {
    expect(config('ai.anthropic.api_key'))->toBeEmpty();
});

it('has the official anthropic sdk installed', function () {
    expect(class_exists(Client::class))->toBeTrue();
});

it('classifies with low effort and a body limit by default', function () {
    expect(config('ai.classification.effort'))->toBe('low')
        ->and(config('ai.classification.max_body_chars'))->toBeGreaterThan(0);
});
