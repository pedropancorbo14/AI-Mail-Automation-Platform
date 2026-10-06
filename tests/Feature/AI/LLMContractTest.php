<?php

use App\AI\Data\LLMRequest;
use App\AI\Data\LLMUsage;
use App\AI\Exceptions\LLMConfigurationException;
use App\AI\Exceptions\LLMInvalidResponseException;
use App\AI\Exceptions\LLMRateLimitException;
use App\AI\Exceptions\LLMRefusalException;
use App\AI\Exceptions\LLMUnavailableException;
use Tests\Fakes\FakeLLMProvider;

it('builds a single message request', function () {
    $request = LLMRequest::single('Eres un clasificador.', 'Hola', 1024, ['type' => 'object'], 'low');

    expect($request->messages)->toBe([['role' => 'user', 'content' => 'Hola']])
        ->and($request->effort)->toBe('low')
        ->and($request->model)->toBeNull();
});

it('rejects invalid requests', function (Closure $build) {
    $build();
})->throws(InvalidArgumentException::class)->with([
    'sin mensajes' => fn () => new LLMRequest('s', [], 100),
    'empieza por assistant' => fn () => new LLMRequest('s', [['role' => 'assistant', 'content' => 'x']], 100),
    'esfuerzo inventado' => fn () => LLMRequest::single('s', 'u', 100, effort: 'turbo'),
    'sin tokens' => fn () => LLMRequest::single('s', 'u', 0),
]);

it('adds up all billed tokens', function () {
    expect((new LLMUsage(100, 50, 20, 1000))->totalTokens())->toBe(1170);
});

it('tells which errors are worth retrying', function () {
    expect((new LLMRateLimitException('x'))->retryable)->toBeTrue()
        ->and((new LLMUnavailableException('x'))->retryable)->toBeTrue()
        ->and((new LLMConfigurationException('x'))->retryable)->toBeFalse()
        ->and((new LLMRefusalException('x'))->retryable)->toBeFalse()
        ->and((new LLMInvalidResponseException('x'))->retryable)->toBeFalse();
});

it('fakes a provider with queued responses and errors', function () {
    $fake = (new FakeLLMProvider())->respondWith(['intent' => 'order_status'], new LLMRateLimitException('Límite'));

    expect($fake->generate(LLMRequest::single('s', 'u', 10))->data)->toBe(['intent' => 'order_status'])
        ->and(fn () => $fake->generate(LLMRequest::single('s', 'u', 10)))->toThrow(LLMRateLimitException::class)
        ->and($fake->requests)->toHaveCount(2);
});
