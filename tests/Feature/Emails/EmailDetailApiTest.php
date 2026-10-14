<?php

use App\AI\Contracts\LLMProvider;
use App\AI\Embeddings\EmbeddingProvider;
use App\Automation\EmailProcessor;
use App\Mail\Enums\AttachmentStatus;
use App\Mail\Enums\RecipientType;
use App\Models\Email;
use App\Models\EmailAttachment;
use App\Models\MailConnection;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Fakes\FakeEmbeddingProvider;
use Tests\Fakes\FakeLLMProvider;

beforeEach(function () {
    Storage::fake('local');
    $this->tenant = Tenant::factory()->create(['slug' => 'empresa-demo']); // modo draft por defecto
    enableTools($this->tenant, 'get_order', 'get_customer_orders');
    $this->user = User::factory()->for($this->tenant)->create();

    $this->email = inTenant($this->tenant, function () {
        $email = Email::factory()->create([
            'mail_connection_id' => MailConnection::factory()->create(['name' => 'Soporte']),
            'from_email' => 'cliente@cliente.test',
            'from_name' => 'Cliente Pérez',
            'subject' => '¿Dónde está mi pedido #45821?',
            'text_body' => 'Hola, ¿cuándo me llega el pedido 45821?',
            'html_body' => '<p>Hola, ¿cuándo me llega el <b>pedido 45821</b>?</p><script>alert(1)</script><img src="https://rastreo.test/p.gif">',
            'message_id' => 'pedido-45821@cliente.test',
        ]);
        $email->recipients()->createMany([
            ['type' => RecipientType::To, 'email' => 'soporte@empresa.test', 'name' => 'Soporte', 'position' => 0],
            ['type' => RecipientType::Cc, 'email' => 'jefe@cliente.test', 'name' => null, 'position' => 0],
        ]);

        return $email;
    });
});

/** Procesa el email con la IA simulada (clasificación + get_order + propuesta). */
function processDetailEmail(): void
{
    config(['ai.anthropic.api_key' => 'sk-ant-x']);
    $llm = (new FakeLLMProvider())->respondWith(
        classificationData(),
        FakeLLMProvider::toolUse([['get_order', ['order_number' => '45821']]]),
        agentReplyData(),
    );
    app()->instance(LLMProvider::class, $llm);
    app()->instance(EmbeddingProvider::class, new FakeEmbeddingProvider());

    inTenant(test()->tenant, fn () => app(EmailProcessor::class)->process(test()->email->fresh()));
}

function storedAttachment(Email $email, string $filename, string $content, AttachmentStatus $status = AttachmentStatus::Stored): EmailAttachment
{
    return inTenant($email->tenant, function () use ($email, $filename, $content, $status) {
        $attachment = $email->attachments()->create([
            'provider_attachment_id' => (string) random_int(1, 9999), 'filename' => $filename,
            'content_type' => 'text/html', 'size' => strlen($content), 'inline' => false,
        ]);

        if ($status === AttachmentStatus::Stored) {
            $path = "tenants/{$email->tenant_id}/emails/{$email->id}/{$attachment->id}";
            Storage::disk('local')->put($path, $content);
            $attachment->markStored('local', $path, hash('sha256', $content), strlen($content));
        } else {
            $attachment->markNotStored($status, 'Supera el tamaño máximo.');
        }

        return $attachment;
    });
}

function getAsUser(string $uri): Illuminate\Testing\TestResponse
{
    Sanctum::actingAs(test()->user);

    return test()->getJson($uri);
}

it('shows everything that happened with an email', function () {
    processDetailEmail();
    storedAttachment($this->email, 'justificante.pdf', '%PDF');

    $response = getAsUser("/api/v1/emails/{$this->email->id}")->assertOk();

    $response->assertJsonPath('data.subject', '¿Dónde está mi pedido #45821?')
        ->assertJsonPath('data.status', 'human_review')
        ->assertJsonPath('data.to', [['email' => 'soporte@empresa.test', 'name' => 'Soporte']])
        ->assertJsonPath('data.cc', [['email' => 'jefe@cliente.test', 'name' => null]])
        ->assertJsonPath('data.reply_to', [])
        ->assertJsonPath('data.text_body', 'Hola, ¿cuándo me llega el pedido 45821?')
        ->assertJsonPath('data.has_html', true)
        ->assertJsonMissingPath('data.html_body')
        ->assertJsonPath('data.intent', 'order_status')
        ->assertJsonPath('data.classification.entities.order_numbers', ['45821'])
        ->assertJsonPath('data.classification.model', 'claude-opus-5-5')
        ->assertJsonPath('data.attachments.0.filename', 'justificante.pdf')
        ->assertJsonPath('data.attachments.0.status', 'stored')
        ->assertJsonMissingPath('data.attachments.0.path')
        ->assertJsonPath('data.attachments_count', 1);

    expect($response->json('data.status_history.*.to'))->toBe(['processing', 'ai_review', 'human_review'])
        ->and($response->json('data.status_history.2.reason'))->toContain('modo «Borradores»')
        ->and($response->json('data.status_history.2.user'))->toBeNull();

    $response->assertJsonPath('data.replies.0.status', 'draft')
        ->assertJsonPath('data.replies.0.body', agentReplyData()['reply_body'])
        ->assertJsonPath('data.replies.0.confidence', 0.9)
        ->assertJsonPath('data.replies.0.sources', [['type' => 'order', 'reference' => '45821']]);

    $response->assertJsonPath('data.agent_runs.0.stop_reason', 'end_turn')
        ->assertJsonPath('data.agent_runs.0.stop_reason_label', 'Respuesta final')
        ->assertJsonCount(2, 'data.agent_runs.0.steps')
        ->assertJsonPath('data.agent_runs.0.steps.0.tool_calls.0.tool', 'get_order')
        ->assertJsonPath('data.agent_runs.0.steps.0.tool_calls.0.arguments', ['order_number' => '45821'])
        ->assertJsonPath('data.agent_runs.0.steps.0.tool_calls.0.status', 'success')
        ->assertJsonPath('data.agent_runs.0.steps.0.tool_calls.0.result.shipment.tracking_number', 'SEU123456789ES')
        ->assertJsonPath('data.agent_runs.0.steps.1.tool_calls', []);

    expect($response->json('data.ai_cost_usd'))->toBeGreaterThan(0);
});

it('shows an email that was not processed yet', function () {
    getAsUser("/api/v1/emails/{$this->email->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'received')
        ->assertJsonPath('data.classification', null)
        ->assertJsonPath('data.replies', [])
        ->assertJsonPath('data.agent_runs', [])
        ->assertJsonPath('data.ai_cost_usd', 0);
});

it('only returns the html once sanitized', function () {
    getAsUser("/api/v1/emails/{$this->email->id}/html")
        ->assertOk()
        ->assertJsonPath('data.html', fn (string $html) => str_contains($html, '<b>pedido 45821</b>') && ! str_contains($html, 'script'))
        ->assertJsonPath('data.remote_images', true);

    inTenant($this->tenant, fn () => $this->email->forceFill(['html_body' => null])->save());

    getAsUser("/api/v1/emails/{$this->email->id}/html")->assertOk()->assertExactJson(['data' => ['html' => null, 'remote_images' => false]]);
});

it('downloads attachments always as a file, never to be shown in the browser', function () {
    $attachment = storedAttachment($this->email, 'factura <script>.html', '<script>alert(1)</script>');

    $response = getAsUser("/api/v1/emails/{$this->email->id}/attachments/{$attachment->id}")->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/octet-stream')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
        ->and($response->streamedContent())->toBe('<script>alert(1)</script>');
});

it('does not download attachments that were not stored or belong to another email', function () {
    $skipped = storedAttachment($this->email, 'enorme.zip', '', AttachmentStatus::Skipped);
    $otherEmail = inTenant($this->tenant, fn () => Email::factory()->create());
    $foreign = storedAttachment($otherEmail, 'otro.pdf', '%PDF');

    getAsUser("/api/v1/emails/{$this->email->id}/attachments/{$skipped->id}")->assertNotFound();
    getAsUser("/api/v1/emails/{$this->email->id}/attachments/{$foreign->id}")->assertNotFound();
});

it('never shows an email of another company: the same 404 as one that does not exist', function (string $suffix) {
    $other = Tenant::factory()->create();
    $theirs = inTenant($other, fn () => Email::factory()->create(['subject' => 'Secreto de Globex']));
    $theirAttachment = storedAttachment($theirs, 'secreto.pdf', 'secreto');
    $suffix = str_replace('{attachment}', (string) $theirAttachment->id, $suffix);

    $response = getAsUser("/api/v1/emails/{$theirs->id}{$suffix}")->assertNotFound();

    expect($response->json())->toBe(['message' => 'Recurso no encontrado.'])
        ->and(getAsUser("/api/v1/emails/999999{$suffix}")->json())->toBe(['message' => 'Recurso no encontrado.']);
})->with(['', '/html', '/attachments/{attachment}']);

it('requires the emails.view permission', function () {
    Role::where('name', Role::AGENT)->sole()->permissions()->detach(Permission::where('name', Permission::EMAILS_VIEW)->sole());

    getAsUser("/api/v1/emails/{$this->email->id}")->assertForbidden();
    getAsUser("/api/v1/emails/{$this->email->id}/html")->assertForbidden();
});
