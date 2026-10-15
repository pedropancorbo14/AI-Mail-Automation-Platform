<?php

use App\AI\Contracts\LLMProvider;
use App\AI\Embeddings\EmbeddingProvider;
use App\Automation\AutomationMode;
use App\Automation\EmailProcessor;
use App\Mail\Enums\EmailStatus;
use App\Mail\Enums\MailConnectionStatus;
use App\Mail\Enums\MailProviderType;
use App\Mail\Exceptions\MailConnectionException;
use App\Mail\Ingestion\MailSyncService;
use App\Mail\MailProviderFactory;
use App\Models\Email;
use App\Models\MailConnection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeEmbeddingProvider;
use Tests\Fakes\FakeLLMProvider;
use Tests\Fakes\FakeMailProvider;

beforeEach(function () {
    Storage::fake('local');
    config(['ai.anthropic.api_key' => 'sk-ant-x']);
    $this->llm = new FakeLLMProvider();
    app()->instance(LLMProvider::class, $this->llm);
    app()->instance(EmbeddingProvider::class, new FakeEmbeddingProvider());
    $this->mail = new FakeMailProvider(['INBOX' => []]);
    app(MailProviderFactory::class)->extend(MailProviderType::Imap, fn () => $this->mail);

    $this->tenant = Tenant::factory()->create(['slug' => 'empresa-demo']);
    $this->tenant->configureAutomation(AutomationMode::Auto);
    enableTools($this->tenant, 'get_order');
    $this->connection = inTenant($this->tenant, fn () => MailConnection::factory()->create(['email' => 'soporte@empresa.test']));
    $this->connection->forceFill(['status' => MailConnectionStatus::Active])->save();
});

function metricsEmail(array $attributes = []): Email
{
    return inTenant(test()->tenant, fn () => Email::factory()->create($attributes + [
        'mail_connection_id' => test()->connection->id,
        'from_email' => 'cliente@cliente.test',
        'subject' => '¿Dónde está mi pedido #45821?',
        'text_body' => 'Hola, ¿cuándo me llega?',
    ]));
}

function metricsProcess(Email $email): ?EmailStatus
{
    return inTenant(test()->tenant, fn () => app(EmailProcessor::class)->process($email->fresh()));
}

it('counts the emails stored by the ingestion once, by mailbox type and kind', function () {
    config(['ai.automation.process_on_ingest' => false]); // aquí solo interesa la ingesta
    $sync = fn () => app(MailSyncService::class)->sync($this->connection->fresh());
    $sync(); // primera pasada: fija el punto de partida

    $this->mail->mailboxes['INBOX'][] = incomingMessage(['id' => '1', 'messageId' => 'a@cliente.test', 'attachments' => []]);
    $this->mail->mailboxes['INBOX'][] = incomingMessage(['id' => '2', 'messageId' => 'b@cliente.test', 'attachments' => [], 'automatedReason' => 'Auto-Submitted: auto-replied']);
    $this->mail->mailboxes['INBOX'][] = incomingMessage(['id' => '3', 'messageId' => 'a@cliente.test', 'attachments' => []]); // duplicado
    $sync();

    expect(metricSample('emails_ingested_total', ['tenant' => 'empresa-demo', 'provider' => 'imap', 'automated' => false]))->toBe(1.0)
        ->and(metricSample('emails_ingested_total', ['tenant' => 'empresa-demo', 'provider' => 'imap', 'automated' => true]))->toBe(1.0)
        ->and(metricSample('mail_syncs_total', ['tenant' => 'empresa-demo', 'provider' => 'imap', 'result' => 'success']))->toBe(2.0);
});

it('counts failed mailbox syncs', function () {
    $this->mail->failNewMessagesWith = new MailConnectionException('Servidor caído');

    expect(fn () => app(MailSyncService::class)->sync($this->connection->fresh()))->toThrow(MailConnectionException::class)
        ->and(metricSample('mail_syncs_total', ['result' => 'failure']))->toBe(1.0)
        ->and(metricSample('mail_syncs_total', ['result' => 'success']))->toBeNull();
});

it('measures an email answered on its own: states, decision time and the automatic reply', function () {
    $email = metricsEmail();
    $this->llm->respondWith(classificationData(), FakeLLMProvider::toolUse([['get_order', ['order_number' => '45821']]]), agentReplyData());

    expect(metricsProcess($email))->toBe(EmailStatus::Sent);

    foreach (['processing', 'ai_review', 'approved', 'sent'] as $status) {
        expect(metricSample('email_status_changes_total', ['tenant' => 'empresa-demo', 'to' => $status, 'by' => 'platform']))->toBe(1.0);
    }

    expect(metricSample('email_processing_seconds_count', ['tenant' => 'empresa-demo', 'outcome' => 'approved']))->toBe(1.0)
        ->and(metricSample('email_processing_seconds_count'))->toBe(1.0) // solo la decisión, no el envío
        ->and(metricSample('email_replies_sent_total', ['tenant' => 'empresa-demo', 'automatic' => true]))->toBe(1.0)
        ->and(metricSample('ai_agent_replies_total', ['tenant' => 'empresa-demo', 'needs_human' => false]))->toBe(1.0);
});

it('measures an email passed to a person', function () {
    $this->tenant->configureAutomation(AutomationMode::Draft);
    $email = metricsEmail();
    $this->llm->respondWith(classificationData(), agentReplyData());

    expect(metricsProcess($email))->toBe(EmailStatus::HumanReview)
        ->and(metricSample('email_processing_seconds_count', ['outcome' => 'human_review']))->toBe(1.0)
        ->and(metricSample('email_replies_sent_total'))->toBeNull()
        ->and(metricSample('ai_agent_replies_total', ['needs_human' => false]))->toBe(1.0);
});

it('counts failed replies', function () {
    $email = metricsEmail();
    $this->mail->failSendWith = new App\Mail\Exceptions\MailProviderException('Destinatario rechazado');
    $this->llm->respondWith(classificationData(), FakeLLMProvider::toolUse([['get_order', ['order_number' => '45821']]]), agentReplyData());

    metricsProcess($email);

    expect(metricSample('email_replies_failed_total', ['tenant' => 'empresa-demo']))->toBe(1.0)
        ->and(metricSample('email_status_changes_total', ['to' => 'failed']))->toBe(1.0);
});

it('tells changes made by a person apart and does not time them as a decision', function () {
    $email = metricsEmail();
    $user = User::factory()->for($this->tenant)->create();

    inTenant($this->tenant, fn () => $email->fresh()->transitionTo(EmailStatus::Ignored, 'Lo ignora una persona', $user));

    expect(metricSample('email_status_changes_total', ['to' => 'ignored', 'by' => 'user']))->toBe(1.0)
        ->and(metricSample('email_processing_seconds_count'))->toBeNull();
});

it('does not count anything that was rolled back', function () {
    try {
        DB::transaction(function () {
            metricsEmail();
            throw new RuntimeException('falla después de guardar');
        });
    } catch (RuntimeException) {
    }

    expect(metricSample('emails_ingested_total'))->toBeNull();
});
