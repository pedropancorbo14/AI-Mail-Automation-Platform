<?php

use App\AI\Agent\Enums\EmailReplyStatus;
use App\AI\Contracts\LLMProvider;
use App\AI\Embeddings\EmbeddingProvider;
use App\AI\Exceptions\LLMConfigurationException;
use App\AI\Exceptions\LLMInvalidResponseException;
use App\AI\Exceptions\LLMRefusalException;
use App\AI\Exceptions\LLMUnavailableException;
use App\Automation\AutomationMode;
use App\Automation\EmailProcessor;
use App\Mail\Enums\EmailStatus;
use App\Mail\Enums\MailConnectionStatus;
use App\Mail\Enums\MailProviderType;
use App\Mail\MailProviderFactory;
use App\Models\Email;
use App\Models\EmailStatusChange;
use App\Models\MailConnection;
use App\Models\Tenant;
use Tests\Fakes\FakeEmbeddingProvider;
use Tests\Fakes\FakeLLMProvider;
use Tests\Fakes\FakeMailProvider;

beforeEach(function () {
    config(['ai.anthropic.api_key' => 'sk-ant-x']);
    $this->llm = new FakeLLMProvider();
    app()->instance(LLMProvider::class, $this->llm);
    app()->instance(EmbeddingProvider::class, new FakeEmbeddingProvider());
    $this->mail = new FakeMailProvider();
    app(MailProviderFactory::class)->extend(MailProviderType::Imap, fn () => $this->mail);

    $this->tenant = Tenant::factory()->create(['slug' => 'empresa-demo']);
    $this->tenant->configureAutomation(AutomationMode::Auto);
    enableTools($this->tenant, 'get_order', 'get_customer_orders');

    $this->email = inTenant($this->tenant, fn () => Email::factory()->create([
        'mail_connection_id' => MailConnection::factory()->create(['email' => 'soporte@empresa.test', 'status' => MailConnectionStatus::Active]),
        'from_email' => 'cliente@cliente.test',
        'subject' => '¿Dónde está mi pedido #45821?',
        'text_body' => 'Hola, ¿cuándo me llega el pedido 45821?',
    ]));
});

function processEmail(bool $reprocess = false): ?EmailStatus
{
    return inTenant(test()->tenant, fn () => app(EmailProcessor::class)->process(test()->email->fresh(), $reprocess));
}

/** @return list<string> Estados por los que ha pasado el email. */
function statusPath(): array
{
    return inTenant(test()->tenant, fn () => test()->email->statusChanges()->get()
        ->map(fn (EmailStatusChange $change) => $change->to_status->value)->all());
}

function lastStatusReason(): ?string
{
    return inTenant(test()->tenant, fn () => test()->email->statusChanges()->get()->last()?->reason);
}

it('answers a safe email on its own from start to end', function () {
    $this->llm->respondWith(
        classificationData(),
        FakeLLMProvider::toolUse([['get_order', ['order_number' => '45821']]]),
        agentReplyData(),
    );

    expect(processEmail())->toBe(EmailStatus::Sent) // cola sync: el envío ya se ha hecho
        ->and(statusPath())->toBe(['processing', 'ai_review', 'approved', 'sent'])
        ->and($this->email->fresh()->status)->toBe(EmailStatus::Sent)
        ->and($this->mail->sent)->toHaveCount(1)
        ->and($this->mail->sent[0]->textBody)->toContain('SEU123456789ES')
        ->and($this->mail->sent[0]->autoSubmitted)->toBeTrue()
        ->and($this->llm->requests)->toHaveCount(3);

    $reply = inTenant($this->tenant, fn () => $this->email->replies()->sole());
    expect($reply->status)->toBe(EmailReplyStatus::Sent)
        ->and($reply->sent_automatically)->toBeTrue();
});

it('leaves the proposal to a person in draft mode', function () {
    $this->tenant->configureAutomation(AutomationMode::Draft);
    $this->llm->respondWith(classificationData(), agentReplyData());

    expect(processEmail())->toBe(EmailStatus::HumanReview)
        ->and(statusPath())->toBe(['processing', 'ai_review', 'human_review'])
        ->and(lastStatusReason())->toContain('modo «Borradores»')
        ->and($this->mail->sent)->toBe([])
        ->and(inTenant($this->tenant, fn () => $this->email->replies()->sole()->status))->toBe(EmailReplyStatus::Draft);
});

it('sends the proposal to a person when the rules ask for it', function () {
    $this->llm->respondWith(classificationData(), agentReplyData(['confidence' => 0.5]));

    expect(processEmail())->toBe(EmailStatus::HumanReview)
        ->and(lastStatusReason())->toContain('Confianza baja (0.50 < 0.75)')
        ->and($this->mail->sent)->toBe([]);
});

it('ignores automated emails without spending on the ai', function () {
    inTenant($this->tenant, fn () => $this->email->forceFill(['automated_reason' => 'Mensaje automático (Auto-Submitted: auto-replied).'])->save());

    expect(processEmail())->toBe(EmailStatus::Ignored)
        ->and(lastStatusReason())->toBe('Correo automático: Mensaje automático (Auto-Submitted: auto-replied).')
        ->and($this->llm->requests)->toBe([]);
});

it('goes straight to a person when', function (Closure $setup, string $reason) {
    $setup();

    expect(processEmail())->toBe(EmailStatus::HumanReview)
        ->and(statusPath())->toBe(['processing', 'human_review'])
        ->and(lastStatusReason())->toContain($reason)
        ->and($this->llm->requests)->toBe([]);
})->with([
    'la empresa no usa IA' => [fn () => test()->tenant->configureAutomation(AutomationMode::Off), 'automatización está desactivada'],
    'la IA no está configurada' => [fn () => config(['ai.anthropic.api_key' => '']), 'falta ANTHROPIC_API_KEY'],
]);

it('ignores spam and automatic replies when the classifier is sure', function (string $intent) {
    $this->llm->respondWith(classificationData(['intent' => $intent, 'confidence' => 0.95]));

    expect(processEmail())->toBe(EmailStatus::Ignored)
        ->and(lastStatusReason())->toBe("Clasificado como {$intent} (confianza 0.95): no necesita respuesta.")
        ->and($this->llm->requests)->toHaveCount(1);
})->with(['spam', 'auto_reply']);

it('does not ignore an email when the classifier is not sure it is spam', function () {
    $this->llm->respondWith(classificationData(['intent' => 'spam', 'confidence' => 0.6]), agentReplyData());

    expect(processEmail())->toBe(EmailStatus::HumanReview)
        ->and(lastStatusReason())->toContain('intención «spam»')
        ->and($this->llm->requests)->toHaveCount(2);
});

it('does not run the agent on a suspected prompt injection', function () {
    $this->llm->respondWith(classificationData(['intent' => 'other', 'injection_suspected' => true]));

    expect(processEmail())->toBe(EmailStatus::HumanReview)
        ->and(lastStatusReason())->toContain('manipular a la IA')
        ->and($this->llm->requests)->toHaveCount(1);
});

it('sends the email to a person when the agent cannot prepare a reply', function () {
    $this->llm->respondWith(classificationData(), new LLMRefusalException('El modelo rechazó la petición por seguridad.'));

    expect(processEmail())->toBe(EmailStatus::HumanReview)
        ->and(statusPath())->toBe(['processing', 'human_review'])
        ->and(lastStatusReason())->toContain('La IA no pudo preparar una respuesta (El modelo rechazó la petición por seguridad.)');
});

it('does not classify twice', function () {
    inTenant($this->tenant, fn () => $this->email->recordClassification(App\AI\Classification\EmailClassification::fromArray(classificationData())));
    $this->llm->respondWith(agentReplyData());

    processEmail();

    expect($this->llm->requests)->toHaveCount(1)
        ->and($this->llm->requests[0]->tools)->not->toBe([]);
});

it('keeps the email processing on temporary errors and resumes on the retry', function () {
    $this->llm->respondWith(classificationData(), new LLMUnavailableException('Anthropic no está disponible (529).'));

    expect(fn () => processEmail())->toThrow(LLMUnavailableException::class);
    expect($this->email->fresh()->status)->toBe(EmailStatus::Processing);

    $this->llm->respondWith(agentReplyData());

    expect(processEmail())->toBe(EmailStatus::Sent) // cola sync: el envío ya se ha hecho
        ->and(statusPath())->toBe(['processing', 'ai_review', 'approved', 'sent'])
        ->and($this->llm->requests)->toHaveCount(3); // la clasificación no se repite
});

it('reuses the proposal of an earlier attempt instead of paying again', function () {
    inTenant($this->tenant, function () {
        $this->email->transitionTo(EmailStatus::Processing);
        $this->email->recordClassification(App\AI\Classification\EmailClassification::fromArray(classificationData()));
        draftReply($this->email);
    });

    expect(processEmail())->toBe(EmailStatus::Sent) // cola sync: el envío ya se ha hecho
        ->and($this->llm->requests)->toBe([]);
});

it('handles classification errors that will not fix themselves', function (Throwable $error, EmailStatus $status, string $reason) {
    $this->llm->respondWith($error);

    expect(processEmail())->toBe($status)
        ->and(lastStatusReason())->toContain($reason);
})->with([
    'clave o saldo' => [new LLMConfigurationException('Anthropic rechazó la API key (401).'), EmailStatus::Failed, 'La IA no está bien configurada: Anthropic rechazó la API key'],
    'respuesta inválida' => [new LLMInvalidResponseException('La IA devolvió una respuesta que no es JSON.'), EmailStatus::HumanReview, 'No se pudo clasificar con la IA'],
]);

it('marks the email as failed on unexpected errors', function () {
    $this->llm->respondWith(new RuntimeException('Error inesperado'));

    expect(fn () => processEmail())->toThrow(RuntimeException::class);

    expect($this->email->fresh()->status)->toBe(EmailStatus::Failed)
        ->and(lastStatusReason())->toBe('Error interno al procesar el email.');
});

it('only reprocesses decided emails when asked to', function () {
    $this->tenant->configureAutomation(AutomationMode::Draft);
    $this->llm->respondWith(classificationData(), agentReplyData());
    processEmail();

    expect(processEmail())->toBeNull()
        ->and($this->llm->requests)->toHaveCount(2);

    $this->llm->respondWith(agentReplyData(['reply_body' => 'Segunda propuesta']));

    expect(processEmail(reprocess: true))->toBe(EmailStatus::HumanReview)
        ->and($this->llm->requests)->toHaveCount(3) // otra vez el agente, no la clasificación
        ->and(inTenant($this->tenant, fn () => $this->email->replies()->first()->body))->toBe('Segunda propuesta')
        ->and(statusPath())->toContain('processing');
});

it('does not touch emails that are already being answered', function (EmailStatus $status) {
    inTenant($this->tenant, fn () => $this->email->forceFill(['status' => $status])->save());

    expect(processEmail(reprocess: true))->toBeNull()
        ->and($this->llm->requests)->toBe([]);
})->with([EmailStatus::AiReview, EmailStatus::Approved, EmailStatus::Sent]);

it('must run inside the email company', function () {
    app(EmailProcessor::class)->process($this->email);
})->throws(LogicException::class);
