<?php

use App\AI\Contracts\LLMProvider;
use App\AI\Embeddings\EmbeddingProvider;
use App\AI\Exceptions\LLMUnavailableException;
use App\Models\Email;
use App\Models\Tenant;
use Tests\Fakes\FakeEmbeddingProvider;
use Tests\Fakes\FakeLLMProvider;

beforeEach(function () {
    config(['ai.anthropic.api_key' => 'sk-ant-x']);
    $this->llm = new FakeLLMProvider();
    app()->instance(LLMProvider::class, $this->llm);
    app()->instance(EmbeddingProvider::class, new FakeEmbeddingProvider());

    $this->tenant = Tenant::factory()->create(['slug' => 'empresa-demo']);
    enableTools($this->tenant, 'get_order', 'get_customer_orders');
    $this->email = inTenant($this->tenant, fn () => Email::factory()->create(['from_email' => 'cliente@cliente.test', 'subject' => '¿Mi pedido?']));
});

it('runs the agent and shows the whole trace', function () {
    $this->llm->respondWith(FakeLLMProvider::toolUse([['get_order', ['order_number' => '45821']]]), agentReplyData());

    $this->artisan('ai:reply', ['email' => $this->email->id])
        ->expectsOutputToContain('→ get_order({"order_number":"45821"}) [success]')
        ->expectsOutputToContain('end_turn (Respuesta final)')
        ->expectsOutputToContain('SEU123456789ES')
        ->expectsOutputToContain('no (se podría enviar sola)')
        ->expectsOutputToContain('order: 45821')
        ->assertSuccessful();
});

it('shows the last run without calling the ai again', function () {
    $this->llm->respondWith(agentReplyData(['confidence' => 0.5]));
    $this->artisan('ai:reply', ['email' => $this->email->id])->assertSuccessful();

    $this->artisan('ai:reply', ['email' => $this->email->id, '--last' => true])
        ->expectsOutputToContain('Confianza baja')
        ->assertSuccessful();

    expect($this->llm->requests)->toHaveCount(1);
});

it('explains when there is no proposal', function () {
    config(['ai.agent.max_iterations' => 1]);
    $this->llm->respondWith(FakeLLMProvider::toolUse([['get_order', ['order_number' => '45821']]]));

    $this->artisan('ai:reply', ['email' => $this->email->id])
        ->expectsOutputToContain('Sin propuesta de respuesta: Límite de pasos alcanzado')
        ->assertFailed();
});

it('reports temporary errors', function () {
    $this->llm->respondWith(new LLMUnavailableException('Anthropic no está disponible (529).'));

    $this->artisan('ai:reply', ['email' => $this->email->id])
        ->expectsOutputToContain('El agente no pudo terminar')
        ->assertFailed();
});

it('validates its input', function () {
    $this->artisan('ai:reply', ['email' => 999999])->assertFailed();
    $this->artisan('ai:reply', ['email' => $this->email->id, '--last' => true])->expectsOutputToContain('no tiene ejecuciones')->assertFailed();

    config(['ai.anthropic.api_key' => '']);
    $this->artisan('ai:reply', ['email' => $this->email->id])->expectsOutputToContain('ANTHROPIC_API_KEY')->assertFailed();
});
