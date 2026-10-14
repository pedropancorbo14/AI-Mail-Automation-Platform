<?php

use App\AI\Agent\AgentService;
use App\AI\Classification\EmailClassification;
use App\AI\Contracts\LLMProvider;
use App\AI\Embeddings\EmbeddingProvider;
use App\Models\Email;
use App\Models\EmailReply;
use App\Models\Tenant;
use Tests\Fakes\FakeEmbeddingProvider;
use Tests\Fakes\FakeLLMProvider;

beforeEach(function () {
    $this->llm = new FakeLLMProvider();
    app()->instance(LLMProvider::class, $this->llm);
    app()->instance(EmbeddingProvider::class, new FakeEmbeddingProvider());

    $this->tenant = Tenant::factory()->create(['slug' => 'empresa-demo']);
    enableTools($this->tenant, 'get_order', 'get_customer_orders');
    $this->email = inTenant($this->tenant, fn () => Email::factory()->create(['from_email' => 'cliente@cliente.test', 'text_body' => '¿Y mi pedido 45821?']));
});

/** Ejecuta el agente con las respuestas dadas y devuelve la propuesta guardada. */
function decidedReply(mixed ...$responses): EmailReply
{
    test()->llm->respondWith(...$responses);

    return inTenant(test()->tenant, fn () => app(AgentService::class)->run(test()->email)->reply);
}

it('lets a confident and clean reply go without human review', function () {
    $reply = decidedReply(FakeLLMProvider::toolUse([['get_order', ['order_number' => '45821']]]), agentReplyData(['confidence' => 0.9]));

    expect($reply->needs_human)->toBeFalse()
        ->and($reply->needs_human_reason)->toBeNull();
});

it('sends the reply to a person when', function (Closure $setup, array $reply, string $reason) {
    $setup();

    $draft = decidedReply(agentReplyData($reply));

    expect($draft->needs_human)->toBeTrue()
        ->and($draft->needs_human_reason)->toContain($reason);
})->with([
    'la IA lo pide' => [fn () => null, ['needs_human' => true, 'needs_human_reason' => 'Pide un reembolso'], 'La IA lo pide: Pide un reembolso'],
    'la confianza es baja' => [fn () => null, ['confidence' => 0.6], 'Confianza baja (0.60 < 0.75)'],
    'hay acciones pendientes' => [fn () => null, ['pending_actions' => ['Aprobar el reembolso de 349 €']], 'Aprobar el reembolso de 349 €'],
    'hay sospecha de injection' => [fn () => inTenant(test()->tenant, fn () => test()->email->recordClassification(EmailClassification::fromArray(classificationData(['injection_suspected' => true])))), [], 'instrucciones a la IA'],
    'la clasificación lo marcó' => [fn () => inTenant(test()->tenant, fn () => test()->email->recordClassification(EmailClassification::fromArray(classificationData(['needs_human' => true, 'needs_human_reason' => 'Amenaza legal'])))), [], 'Amenaza legal'],
    'el email se recortó' => [function () {
        config(['ai.classification.max_body_chars' => 10]);
    }, [], 'demasiado largo'],
]);

it('sends the reply to a person when a lookup failed, even if the model is confident', function () {
    $draft = decidedReply(FakeLLMProvider::toolUse([['get_order', ['order_number' => '76540']]]), agentReplyData(['confidence' => 0.95]));

    expect($draft->needs_human)->toBeTrue()
        ->and($draft->needs_human_reason)->toContain('get_order');
});

it('keeps every reason', function () {
    $draft = decidedReply(agentReplyData(['confidence' => 0.4, 'needs_human' => true, 'needs_human_reason' => 'Caso raro', 'pending_actions' => ['Llamar al cliente']]));

    expect($draft->needs_human_reason)->toContain('Caso raro')->toContain('Confianza baja')->toContain('Llamar al cliente');
});

it('uses the configured confidence threshold', function () {
    config(['ai.agent.min_confidence' => 0.5]);

    expect(decidedReply(agentReplyData(['confidence' => 0.6]))->needs_human)->toBeFalse();
});
