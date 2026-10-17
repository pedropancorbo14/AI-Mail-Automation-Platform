<?php

use App\AI\Agent\Enums\EmailReplyStatus;
use App\Automation\ProcessEmailJob;
use App\Mail\Enums\EmailStatus;
use App\Mail\Enums\MailConnectionStatus;
use App\Mail\Enums\MailProviderType;
use App\Mail\MailProviderFactory;
use App\Models\AuditLog;
use App\Models\Email;
use App\Models\MailConnection;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Fakes\FakeMailProvider;

beforeEach(function () {
    $this->provider = new FakeMailProvider();
    app(MailProviderFactory::class)->extend(MailProviderType::Imap, fn () => $this->provider);

    $this->tenant = Tenant::factory()->create();
    // Email fijo: la auditoría guarda el del actor y se comprueba que no lleve datos del cliente.
    $this->ana = User::factory()->for($this->tenant)->agent()->create(['name' => 'Ana', 'email' => 'ana@acme.test']);
    [$this->email, $this->reply] = reviewableEmail($this->tenant);
});

/** Email en revisión humana con una propuesta de la IA en borrador. */
function reviewableEmail(Tenant $tenant): array
{
    return inTenant($tenant, function () {
        $email = Email::factory()->create([
            'mail_connection_id' => MailConnection::factory()->create(['status' => MailConnectionStatus::Active]),
            'from_email' => 'cliente@cliente.test',
            'subject' => '¿Dónde está mi pedido #45821?',
            'message_id' => 'pedido-45821@cliente.test',
            'status' => EmailStatus::AiReview,
        ]);
        $reply = draftReply($email, ['needs_human' => true, 'needs_human_reason' => 'Confianza baja.']);
        $email->transitionTo(EmailStatus::HumanReview, 'Confianza baja.');

        return [$email, $reply];
    });
}

function asAna(): Tests\TestCase
{
    app('auth')->forgetGuards();

    return test()->actingAs(test()->ana, 'sanctum');
}

function reviewAudit(string $action): AuditLog
{
    return inTenant(test()->tenant, fn () => AuditLog::where('action', $action)->sole());
}

it('approves the proposal of the ai as it is', function () {
    $response = asAna()->postJson("/api/v1/emails/{$this->email->id}/replies/{$this->reply->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'sent')
        ->assertJsonPath('data.awaiting_person', false)
        ->assertJsonPath('data.replies.0.status', 'sent')
        ->assertJsonPath('data.replies.0.approved_by.name', 'Ana')
        ->assertJsonPath('data.replies.0.sent_automatically', false)
        ->assertJsonPath('data.replies.0.original_body', null)
        ->assertJsonPath('data.reviews.0.decision', 'approved')
        ->assertJsonPath('data.reviews.0.decision_label', 'Aprobada')
        ->assertJsonPath('data.reviews.0.edited', false)
        ->assertJsonPath('data.reviews.0.user.name', 'Ana');

    expect($response->json('data.status_history.*.user.name'))->toContain('Ana')
        ->and($this->provider->sent)->toHaveCount(1)
        ->and(reviewAudit('email.reply_approved')->metadata)->toEqualCanonicalizing(['reply_id' => $this->reply->id, 'edited' => false]);
});

it('approves an edited reply keeping the text of the ai and without customer data in the audit log', function () {
    $aiText = $this->reply->body;

    asAna()->postJson("/api/v1/emails/{$this->email->id}/replies/{$this->reply->id}/approve", ['body' => 'Hola: tu pedido 45821 llega mañana. Ana'])
        ->assertOk()
        ->assertJsonPath('data.replies.0.body', 'Hola: tu pedido 45821 llega mañana. Ana')
        ->assertJsonPath('data.replies.0.original_body', $aiText)
        ->assertJsonPath('data.replies.0.edited_by.name', 'Ana')
        ->assertJsonPath('data.reviews.0.edited', true);

    $audit = reviewAudit('email.reply_approved');

    expect($audit->metadata['edited'])->toBeTrue()
        ->and(json_encode($audit->toArray()))->not->toContain('45821')
        ->and($this->provider->sent[0]->textBody)->toBe('Hola: tu pedido 45821 llega mañana. Ana');
});

it('rejects the proposal with a reason and keeps the email waiting', function () {
    asAna()->postJson("/api/v1/emails/{$this->email->id}/replies/{$this->reply->id}/reject", ['reason' => 'El cliente pide la factura, no el pedido.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'human_review')
        ->assertJsonPath('data.awaiting_person', true)
        ->assertJsonPath('data.replies.0.status', 'rejected')
        ->assertJsonPath('data.replies.0.rejected_by.name', 'Ana')
        ->assertJsonPath('data.replies.0.rejection_reason', 'El cliente pide la factura, no el pedido.')
        ->assertJsonPath('data.reviews.0.decision', 'rejected');

    expect(reviewAudit('email.reply_rejected')->metadata)->toBe(['reply_id' => $this->reply->id])
        ->and($this->provider->sent)->toBe([]);
});

it('sends a reply written by the person', function () {
    asAna()->postJson("/api/v1/emails/{$this->email->id}/replies", ['body' => "Hola:\n\nTe lo miro hoy.\n\nAna"])
        ->assertOk()
        ->assertJsonPath('data.status', 'sent')
        ->assertJsonPath('data.replies.0.author.name', 'Ana')
        ->assertJsonPath('data.replies.0.status', 'sent')
        ->assertJsonPath('data.replies.1.status', 'rejected')
        ->assertJsonPath('data.reviews.0.decision', 'replied');

    expect($this->provider->sent[0]->textBody)->toBe("Hola:\n\nTe lo miro hoy.\n\nAna")
        ->and(reviewAudit('email.replied')->subject_label)->toBe("Email #{$this->email->id}");
});

it('closes the case without replying', function () {
    asAna()->postJson("/api/v1/emails/{$this->email->id}/close", ['reason' => 'Resuelto por teléfono.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'ignored')
        ->assertJsonPath('data.reviews.0.decision', 'closed')
        ->assertJsonPath('data.reviews.0.reason', 'Resuelto por teléfono.');

    expect(reviewAudit('email.closed')->metadata)->toBeNull();
});

it('sends the email back to the ai', function () {
    Queue::fake();

    asAna()->postJson("/api/v1/emails/{$this->email->id}/reprocess")
        ->assertOk()
        ->assertJsonPath('data.status', 'processing')
        ->assertJsonPath('data.reviews.0.decision', 'reprocessed');

    Queue::assertPushed(ProcessEmailJob::class);
    expect(reviewAudit('email.reprocessed'))->not->toBeNull();
});

it('tells the panel when a sending was already attempted and refuses to reprocess', function () {
    Queue::fake();
    inTenant($this->tenant, fn () => draftReply($this->email)->forceFill(['status' => EmailReplyStatus::Failed, 'send_error' => '550'])->save());

    asAna()->getJson("/api/v1/emails/{$this->email->id}")->assertJsonPath('data.send_attempted', true);

    asAna()->postJson("/api/v1/emails/{$this->email->id}/reprocess")
        ->assertConflict()
        ->assertJsonPath('message', 'Ya se intentó enviar una respuesta a este email y puede haber llegado: no se reprocesa con la IA. Responde tú o ciérralo.');

    Queue::assertNothingPushed();
});

it('assigns the case to the person, to a colleague, and releases it', function () {
    $bea = User::factory()->for($this->tenant)->agent()->create(['name' => 'Bea']);

    asAna()->putJson("/api/v1/emails/{$this->email->id}/assignee", ['user_id' => $this->ana->id])
        ->assertOk()
        ->assertJsonPath('data.assignee.name', 'Ana');

    asAna()->putJson("/api/v1/emails/{$this->email->id}/assignee", ['user_id' => $bea->id])
        ->assertOk()
        ->assertJsonPath('data.assignee.name', 'Bea');

    asAna()->putJson("/api/v1/emails/{$this->email->id}/assignee", ['user_id' => null])
        ->assertOk()
        ->assertJsonPath('data.assignee', null)
        ->assertJsonPath('data.status', 'human_review');

    asAna()->getJson('/api/v1/emails')->assertJsonPath('data.0.assignee', null);

    $logs = inTenant($this->tenant, fn () => AuditLog::where('action', 'email.assigned')->orderBy('id')->get());

    expect($logs)->toHaveCount(3)
        ->and($logs[1]->metadata)->toEqualCanonicalizing(['from_user_id' => $this->ana->id, 'to_user_id' => $bea->id, 'to_user' => 'Bea']);
});

it('only assigns people of the company who can review', function () {
    $outsider = User::factory()->for(Tenant::factory())->agent()->create();

    asAna()->putJson("/api/v1/emails/{$this->email->id}/assignee", ['user_id' => $outsider->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('user_id');

    asAna()->putJson("/api/v1/emails/{$this->email->id}/assignee", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('user_id');

    expect($this->email->fresh()->assigned_to)->toBeNull();
});

it('answers 409 when someone else was faster', function () {
    asAna()->postJson("/api/v1/emails/{$this->email->id}/replies/{$this->reply->id}/approve")->assertOk();

    asAna()->postJson("/api/v1/emails/{$this->email->id}/replies/{$this->reply->id}/reject")
        ->assertConflict()
        ->assertJsonPath('message', 'Este email ya no está esperando a una persona: recarga la página para ver su estado.');

    asAna()->postJson("/api/v1/emails/{$this->email->id}/close")->assertConflict();

    expect($this->provider->sent)->toHaveCount(1);
});

it('validates the text of the reply', function () {
    asAna()->postJson("/api/v1/emails/{$this->email->id}/replies", ['body' => ''])
        ->assertUnprocessable()->assertJsonValidationErrors('body');

    asAna()->postJson("/api/v1/emails/{$this->email->id}/replies/{$this->reply->id}/approve", ['body' => str_repeat('a', 20001)])
        ->assertUnprocessable()->assertJsonValidationErrors('body');

    asAna()->postJson("/api/v1/emails/{$this->email->id}/close", ['reason' => str_repeat('a', 1001)])
        ->assertUnprocessable()->assertJsonValidationErrors('reason');

    expect($this->provider->sent)->toBe([]);
});

it('does not accept a reply of another email', function () {
    [$otherEmail, $otherReply] = reviewableEmail($this->tenant);

    asAna()->postJson("/api/v1/emails/{$this->email->id}/replies/{$otherReply->id}/approve")->assertNotFound();

    expect($otherReply->fresh()->status)->toBe(EmailReplyStatus::Draft);
});

it('never lets another company decide on an email', function () {
    $intruder = User::factory()->for(Tenant::factory())->owner()->create();
    Sanctum::actingAs($intruder);
    $id = $this->email->id;

    $this->postJson("/api/v1/emails/{$id}/replies/{$this->reply->id}/approve")->assertNotFound();
    $this->postJson("/api/v1/emails/{$id}/replies/{$this->reply->id}/reject")->assertNotFound();
    $this->postJson("/api/v1/emails/{$id}/replies", ['body' => 'Hola'])->assertNotFound();
    $this->postJson("/api/v1/emails/{$id}/close")->assertNotFound();
    $this->postJson("/api/v1/emails/{$id}/reprocess")->assertNotFound();
    $this->putJson("/api/v1/emails/{$id}/assignee", ['user_id' => $intruder->id])->assertNotFound();

    expect($this->email->fresh()->status)->toBe(EmailStatus::HumanReview)
        ->and($this->provider->sent)->toBe([]);
});

it('requires the emails.review permission', function () {
    Role::where('name', Role::AGENT)->sole()->permissions()->detach(Permission::where('name', Permission::EMAILS_REVIEW)->sole());

    asAna()->postJson("/api/v1/emails/{$this->email->id}/replies/{$this->reply->id}/approve")->assertForbidden();
    asAna()->postJson("/api/v1/emails/{$this->email->id}/replies", ['body' => ''])->assertForbidden();
    asAna()->postJson("/api/v1/emails/{$this->email->id}/close")->assertForbidden();

    expect($this->email->fresh()->status)->toBe(EmailStatus::HumanReview);
});

it('requires authentication', function () {
    $this->postJson("/api/v1/emails/{$this->email->id}/close")->assertUnauthorized();
});
