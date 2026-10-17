<?php

use App\AI\Agent\Enums\EmailReplyStatus;
use App\AI\Remote\Contracts\EventStream;
use App\AI\Remote\EventContract;
use App\Automation\AutomationMode;
use App\Automation\Review\ReviewDecision;
use App\Mail\Enums\EmailStatus;
use App\Mail\Enums\MailConnectionStatus;
use App\Mail\Enums\MailProviderType;
use App\Mail\MailProviderFactory;
use App\Models\AuditLog;
use App\Models\Email;
use App\Models\MailConnection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Fakes\FakeMailProvider;
use Tests\Fakes\InMemoryEventStream;

/*
 * AI-Service, Fase 4: acciones de ai-service que necesitan la aprobación de
 * una persona (el grafo queda en pausa) y reprocesar con una indicación.
 */

beforeEach(function () {
    config(['ai.engine' => 'python', 'ai.anthropic.api_key' => '']);
    $this->streams = new InMemoryEventStream();
    app()->instance(EventStream::class, $this->streams);
    app(MailProviderFactory::class)->extend(MailProviderType::Imap, fn () => new FakeMailProvider());

    $this->tenant = Tenant::factory()->create(['name' => 'Empresa Demo']);
    $this->tenant->configureAutomation(AutomationMode::Auto);
    // Email fijo: la auditoría guarda el del actor y se comprueba que no lleve datos del cliente.
    $this->ana = User::factory()->for($this->tenant)->agent()->create(['name' => 'Ana', 'email' => 'ana@acme.test']);
    $this->email = inTenant($this->tenant, fn () => Email::factory()->create([
        'mail_connection_id' => MailConnection::factory()->create(['email' => 'soporte@empresa.test', 'status' => MailConnectionStatus::Active]),
        'from_email' => 'cliente@cliente.test',
        'subject' => 'La lámpara no enciende',
        'text_body' => 'Hola, la lámpara del pedido #45821 no enciende.',
    ]));
    processRemote($this->email); // publicado: en processing esperando a ai-service
});

/** email.processed.v1 con una acción pendiente (ejemplo compartido) para el email del test. */
function pendingActionEvent(Email $email): array
{
    $event = json_decode(file_get_contents(base_path('contracts/events/examples/valid/email.processed.v1--pending-action.json')), true);

    return array_merge($event, [
        'event_id' => (string) Str::uuid(),
        'tenant_id' => $email->tenant_id,
        'email_id' => $email->id,
        'causation_id' => $email->fresh()->ai_event_id,
        'thread_id' => "t{$email->tenant_id}-e{$email->id}-" . Str::uuid(),
    ]);
}

/** Resultado tras reanudar: redacta con el ticket creado y no vuelve a clasificar. */
function resumedEvent(Email $email, array $overrides = []): array
{
    $event = pendingActionEvent($email);
    unset($event['pending_action'], $event['thread_id']);
    $event['draft'] = "Hola Juan:\n\nHemos abierto el ticket TCK-2001 con tu incidencia.\n\nUn saludo,\nEl equipo de Empresa Demo";
    $event['confidence'] = 0.9;
    $event['decision'] = 'auto_send';
    $event['reasons'] = [];
    $event['sources'] = [['type' => 'ticket', 'reference' => 'TCK-2001']];
    $event['usage']['calls'][0]['purpose'] = 'ai_service.draft';
    $event['usage']['calls'][0]['prompt'] = 'email_draft@4';
    $event['tool_calls'] = [[
        'tool' => 'create_support_ticket',
        'arguments' => ['subject' => 'La lámpara no enciende', 'priority' => 'normal'],
        'result' => ['ticket' => ['id' => 'TCK-2001', 'status' => 'open']],
        'status' => 'success',
        'error' => null,
        'duration_ms' => 40,
    ]];

    return array_merge($event, $overrides);
}

function deliverApprovalResult(array $event, string $type = 'email.processed'): void
{
    test()->streams->publish("{$type}.v1", ['event_id' => $event['event_id'], 'event_type' => $type, 'payload' => json_encode($event)]);
    test()->artisan('ai:consume-results --once')->assertSuccessful();
}

function asReviewer(): Tests\TestCase
{
    app('auth')->forgetGuards();

    return test()->actingAs(test()->ana, 'sanctum');
}

function approvalAudit(string $action): AuditLog
{
    return inTenant(test()->tenant, fn () => AuditLog::where('action', $action)->sole());
}

it('keeps the pending action and sends the email to a person without a proposal', function () {
    deliverApprovalResult($event = pendingActionEvent($this->email));

    $email = $this->email->fresh();
    expect($email->status)->toBe(EmailStatus::HumanReview)
        ->and($email->undecidedAction())->toMatchArray([
            'action_id' => $event['pending_action']['action_id'],
            'tool' => 'create_support_ticket',
            'thread_id' => $event['thread_id'],
        ])
        ->and(inTenant($this->tenant, fn () => $email->replies()->count()))->toBe(0)
        ->and(inTenant($this->tenant, fn () => $email->statusChanges()->reorder('id', 'desc')->value('reason')))
        ->toContain('necesita la aprobación de una persona');

    asReviewer()->getJson("/api/v1/emails/{$email->id}")->assertOk()
        ->assertJsonPath('data.pending_action.tool', 'create_support_ticket')
        ->assertJsonPath('data.pending_action.arguments.priority', 'normal')
        ->assertJsonPath('data.can_instruct', true);

    asReviewer()->getJson('/api/v1/review-queue')->assertOk()
        ->assertJsonPath('data.0.has_pending_action', true);
});

it('approves the action: back to processing and the decision is published for ai-service', function () {
    deliverApprovalResult($event = pendingActionEvent($this->email));

    asReviewer()->postJson("/api/v1/emails/{$this->email->id}/action/approve")->assertOk()
        ->assertJsonPath('data.status', 'processing')
        ->assertJsonPath('data.pending_action', null);

    [$resume] = $this->streams->payloads('email.review_resumed.v1');
    app(EventContract::class)->validate('email.review_resumed.v1', $resume);

    $email = $this->email->fresh();
    expect($resume)->toMatchArray([
        'tenant_id' => $this->tenant->id,
        'email_id' => $email->id,
        'thread_id' => $event['thread_id'],
        'action_id' => $event['pending_action']['action_id'],
        'approved' => true,
        'reviewer_id' => $this->ana->id,
    ])
        ->and($email->ai_event_id)->toBe($resume['event_id'])
        ->and($email->ai_pending_action['approved'])->toBeTrue()
        ->and(inTenant($this->tenant, fn () => $email->reviews()->sole()->decision))->toBe(ReviewDecision::ActionApproved);

    $audit = approvalAudit('email.action_approved');
    expect($audit->metadata)->toBe(['tool' => 'create_support_ticket'])
        ->and(json_encode($audit->toArray()))->not->toContain('lámpara');
});

it('rejects the action: ai-service continues without doing it', function () {
    deliverApprovalResult(pendingActionEvent($this->email));

    asReviewer()->postJson("/api/v1/emails/{$this->email->id}/action/reject")->assertOk();

    expect($this->streams->payloads('email.review_resumed.v1')[0]['approved'])->toBeFalse()
        ->and(inTenant($this->tenant, fn () => $this->email->reviews()->sole()->decision))->toBe(ReviewDecision::ActionRejected);
    approvalAudit('email.action_rejected');
});

it('applies the result after resuming: keeps the original classification and the ticket as evidence', function () {
    deliverApprovalResult(pendingActionEvent($this->email));
    $classification = $this->email->fresh()->classification_ai_request_id;
    asReviewer()->postJson("/api/v1/emails/{$this->email->id}/action/approve")->assertOk();

    deliverApprovalResult(resumedEvent($this->email, ['causation_id' => $this->email->fresh()->ai_event_id]));

    $email = $this->email->fresh();
    $reply = inTenant($this->tenant, fn () => $email->replies()->sole());
    expect($classification)->not->toBeNull()
        ->and($email->classification_ai_request_id)->toBe($classification)
        ->and($email->ai_pending_action)->toBeNull()
        ->and($reply->sources)->toBe([['type' => 'ticket', 'reference' => 'TCK-2001']])
        // technical_support no se envía solo por defecto: lo decide Laravel, no ai-service.
        ->and($email->status)->toBe(EmailStatus::HumanReview)
        ->and($reply->status)->toBe(EmailReplyStatus::Draft);
});

it('refuses a second decision, a missing action and the laravel engine with a 409', function () {
    asReviewer()->postJson("/api/v1/emails/{$this->email->id}/action/approve")->assertStatus(409); // en processing

    deliverApprovalResult(pendingActionEvent($this->email));
    config(['ai.engine' => 'laravel']);
    asReviewer()->postJson("/api/v1/emails/{$this->email->id}/action/approve")->assertStatus(409)
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'ai-service'));

    config(['ai.engine' => 'python']);
    asReviewer()->postJson("/api/v1/emails/{$this->email->id}/action/approve")->assertOk();
    asReviewer()->postJson("/api/v1/emails/{$this->email->id}/action/reject")->assertStatus(409);

    expect($this->streams->payloads('email.review_resumed.v1'))->toHaveCount(1);
});

it('reprocesses with an instruction that travels to ai-service once', function () {
    deliverApprovalResult(pendingActionEvent($this->email));

    asReviewer()->postJson("/api/v1/emails/{$this->email->id}/reprocess", ['instruction' => 'Ofrece cambiarla por otra nueva.'])
        ->assertOk();

    $received = $this->streams->payloads('email.received.v1');
    $email = $this->email->fresh();
    expect(end($received)['review_instruction'])->toBe('Ofrece cambiarla por otra nueva.')
        ->and($email->status)->toBe(EmailStatus::Processing)
        // La acción pendiente se descarta: el reproceso empieza de nuevo.
        ->and($email->ai_pending_action)->toBeNull()
        ->and(inTenant($this->tenant, fn () => $email->reviews()->sole()->reason))->toBe('Ofrece cambiarla por otra nueva.')
        ->and(approvalAudit('email.reprocessed')->metadata)->toBe(['with_instruction' => true]);

    // La indicación se usa una vez: al llegar el resultado se borra.
    deliverApprovalResult(resumedEvent($this->email, ['causation_id' => $email->ai_event_id, 'tool_calls' => [], 'sources' => []]));
    expect($this->email->fresh()->ai_review_instruction)->toBeNull();
});

it('rejects an instruction with the laravel engine instead of ignoring it', function () {
    deliverApprovalResult(pendingActionEvent($this->email));
    config(['ai.engine' => 'laravel']);

    asReviewer()->postJson("/api/v1/emails/{$this->email->id}/reprocess", ['instruction' => 'Sé breve.'])
        ->assertStatus(422)->assertJsonValidationErrors('instruction');
    asReviewer()->postJson("/api/v1/emails/{$this->email->id}/reprocess", ['instruction' => str_repeat('a', 2001)])
        ->assertStatus(422);

    asReviewer()->getJson("/api/v1/emails/{$this->email->id}")->assertJsonPath('data.can_instruct', false);
});

it('republishes a lost decision instead of the email', function () {
    deliverApprovalResult(pendingActionEvent($this->email));
    asReviewer()->postJson("/api/v1/emails/{$this->email->id}/action/approve")->assertOk();
    $received = count($this->streams->payloads('email.received.v1'));

    $this->travel(20)->minutes();
    $this->artisan('ai:reconcile-remote')->assertSuccessful();

    $resumes = $this->streams->payloads('email.review_resumed.v1');
    expect($resumes)->toHaveCount(2)
        ->and($resumes[1]['action_id'])->toBe($resumes[0]['action_id'])
        ->and($resumes[1]['event_id'])->not->toBe($resumes[0]['event_id'])
        ->and($this->streams->payloads('email.received.v1'))->toHaveCount($received);
});

it('sends an expired pause to a person', function () {
    deliverApprovalResult(pendingActionEvent($this->email));
    asReviewer()->postJson("/api/v1/emails/{$this->email->id}/action/approve")->assertOk();

    $failed = json_decode(file_get_contents(base_path('contracts/events/examples/valid/email.processing_failed.v1--expired.json')), true);
    deliverApprovalResult(array_merge($failed, [
        'event_id' => (string) Str::uuid(),
        'tenant_id' => $this->tenant->id,
        'email_id' => $this->email->id,
        'causation_id' => $this->email->fresh()->ai_event_id,
    ]), 'email.processing_failed');

    $email = $this->email->fresh();
    expect($email->status)->toBe(EmailStatus::HumanReview)
        ->and($email->ai_pending_action)->toBeNull();
});

it('never lets another company decide the action', function () {
    deliverApprovalResult(pendingActionEvent($this->email));
    $intruder = User::factory()->for(Tenant::factory())->create();

    app('auth')->forgetGuards();
    $this->actingAs($intruder, 'sanctum')->postJson("/api/v1/emails/{$this->email->id}/action/approve")->assertNotFound();
    $this->actingAs($intruder, 'sanctum')->postJson("/api/v1/emails/{$this->email->id}/reprocess", ['instruction' => 'x'])->assertNotFound();

    expect($this->streams->payloads('email.review_resumed.v1'))->toBe([])
        ->and($this->email->fresh()->undecidedAction())->not->toBeNull();
});
