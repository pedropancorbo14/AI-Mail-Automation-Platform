<?php

use App\AI\Agent\Enums\EmailReplyStatus;
use App\Mail\Enums\EmailStatus;
use App\Models\Email;
use App\Models\MailConnection;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->ana = User::factory()->for($this->tenant)->agent()->create(['name' => 'Ana']);
    $this->mailbox = inTenant($this->tenant, fn () => MailConnection::factory()->create());
});

/** Email que entró en $status hace $minutes minutos (con su historial). */
function queuedEmail(string $subject, EmailStatus $status, int $minutes, array $attributes = [], ?Tenant $tenant = null): Email
{
    $tenant ??= test()->tenant;

    return inTenant($tenant, function () use ($subject, $status, $minutes, $attributes) {
        test()->travel(-$minutes)->minutes();
        $email = Email::factory()->create(['subject' => $subject, 'status' => EmailStatus::Processing, 'mail_connection_id' => test()->mailbox->id ?? MailConnection::factory()]);
        $email->forceFill($attributes)->save();

        if ($status !== EmailStatus::Processing) {
            $email->transitionTo($status === EmailStatus::Sent ? EmailStatus::AiReview : $status, "Motivo de {$subject}");
        }

        test()->travelBack();

        return $email;
    });
}

function queue(string $query = ''): Illuminate\Testing\TestResponse
{
    Sanctum::actingAs(test()->ana);

    return test()->getJson('/api/v1/review-queue' . ($query ? "?{$query}" : ''));
}

it('lists what waits for a person, urgent first and then the oldest', function () {
    queuedEmail('Normal antiguo', EmailStatus::HumanReview, 120, ['urgency' => 'normal']);
    queuedEmail('Normal reciente', EmailStatus::HumanReview, 10, ['urgency' => 'normal']);
    queuedEmail('Urgente reciente', EmailStatus::HumanReview, 5, ['urgency' => 'high']);
    queuedEmail('Con error', EmailStatus::Failed, 60, ['urgency' => 'low']);
    queuedEmail('Ya ignorado', EmailStatus::Ignored, 200);
    queuedEmail('Procesándose', EmailStatus::Processing, 200);

    $response = queue()->assertOk();

    expect($response->json('data.*.subject'))->toBe(['Urgente reciente', 'Normal antiguo', 'Normal reciente', 'Con error'])
        ->and($response->json('data.1.waiting_reason'))->toBe('Motivo de Normal antiguo')
        ->and($response->json('data.1.waiting_since'))->not->toBeNull()
        ->and($response->json('meta.counts'))->toBe(['total' => 4, 'mine' => 0, 'unassigned' => 4, 'failed' => 1]);
});

it('says whether the ai left a proposal and who has the case', function () {
    $withDraft = queuedEmail('Con propuesta', EmailStatus::HumanReview, 20);
    queuedEmail('Sin propuesta', EmailStatus::HumanReview, 10);
    inTenant($this->tenant, fn () => draftReply($withDraft));
    inTenant($this->tenant, fn () => draftReply($withDraft)->forceFill(['status' => EmailReplyStatus::Rejected])->save());
    $withDraft->forceFill(['assigned_to' => $this->ana->id, 'assigned_at' => now()])->save();

    $response = queue()->assertOk();

    expect($response->json('data.0.has_draft'))->toBeTrue()
        ->and($response->json('data.0.assignee'))->toBe(['id' => $this->ana->id, 'name' => 'Ana'])
        ->and($response->json('data.1.has_draft'))->toBeFalse()
        ->and($response->json('data.1.assignee'))->toBeNull();
});

it('filters by assignment, intent and mailbox', function () {
    $mine = queuedEmail('Mío', EmailStatus::HumanReview, 30, ['intent' => 'order_status']);
    queuedEmail('Libre', EmailStatus::HumanReview, 20, ['intent' => 'complaint']);
    $other = queuedEmail('De Bea', EmailStatus::HumanReview, 10, ['intent' => 'complaint']);
    $mine->forceFill(['assigned_to' => $this->ana->id])->save();
    $other->forceFill(['assigned_to' => User::factory()->for($this->tenant)->create()->id])->save();

    expect(queue('assigned=mine')->json('data.*.subject'))->toBe(['Mío'])
        ->and(queue('assigned=unassigned')->json('data.*.subject'))->toBe(['Libre'])
        ->and(queue('intent=complaint')->json('data.*.subject'))->toBe(['Libre', 'De Bea'])
        ->and(queue("mailbox={$this->mailbox->id}")->json('data'))->toHaveCount(3)
        ->and(queue('mailbox=999999')->json('data'))->toBe([])
        ->and(queue('assigned=mine')->json('meta.counts.mine'))->toBe(1);

    queue('assigned=todos')->assertUnprocessable()->assertJsonValidationErrors('assigned');
});

it('never shows emails of another company', function () {
    $other = Tenant::factory()->create();
    queuedEmail('De Globex', EmailStatus::HumanReview, 10, tenant: $other);

    queue()->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.counts.total', 0);
});

it('requires being logged in', function () {
    $this->getJson('/api/v1/review-queue')->assertUnauthorized();
});

it('warns the reviewer about problems in the proposal', function () {
    $email = queuedEmail('¿Dónde está mi pedido 45821?', EmailStatus::HumanReview, 5, ['from_email' => 'cliente@cliente.test', 'text_body' => 'Mi pedido 45821']);
    inTenant($this->tenant, fn () => draftReply($email, ['body' => "Tu pedido #45821 cuesta 99,00 €. Mira https://phishing.test/x\n\nUna persona revisará tu caso."]));

    Sanctum::actingAs($this->ana);
    $checks = $this->getJson("/api/v1/emails/{$email->id}")->assertOk()->json('data.replies.0.checks');

    expect($checks)->toHaveCount(2)
        ->and($checks[0])->toContain('phishing.test')
        ->and($checks[1])->toContain('99,00')
        // Una persona la revisa: prometer una revisión humana ya no es un problema.
        ->and(implode(' ', $checks))->not->toContain('una persona revisará');
});

it('checks an edited text before sending it', function () {
    $email = queuedEmail('Pedido', EmailStatus::HumanReview, 5, ['from_email' => 'cliente@cliente.test', 'text_body' => 'Mi pedido 45821']);
    $reply = inTenant($this->tenant, fn () => draftReply($email));
    Sanctum::actingAs($this->ana);

    $this->postJson("/api/v1/emails/{$email->id}/review-checks", ['body' => 'Tu pedido #45821 está en camino.', 'reply_id' => $reply->id])
        ->assertOk()
        ->assertJsonPath('data.warnings', []);

    $this->postJson("/api/v1/emails/{$email->id}/review-checks", ['body' => 'Tu pedido #99999 está en camino. Escríbenos a otro@raro.test'])
        ->assertOk()
        ->assertJsonCount(2, 'data.warnings');

    // La propuesta tiene que ser de ese email.
    $other = queuedEmail('Otro', EmailStatus::HumanReview, 5);
    $foreign = inTenant($this->tenant, fn () => draftReply($other));

    $this->postJson("/api/v1/emails/{$email->id}/review-checks", ['body' => 'Hola', 'reply_id' => $foreign->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reply_id');
});
