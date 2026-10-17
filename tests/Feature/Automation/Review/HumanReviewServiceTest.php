<?php

use App\AI\Agent\Enums\EmailReplyStatus;
use App\Automation\ProcessEmailJob;
use App\Automation\Review\HumanReviewService;
use App\Automation\Review\ReviewDecision;
use App\Automation\Review\ReviewException;
use App\Mail\Enums\EmailStatus;
use App\Mail\Enums\MailConnectionStatus;
use App\Mail\Enums\MailProviderType;
use App\Mail\MailProviderFactory;
use App\Models\Email;
use App\Models\EmailReply;
use App\Models\MailConnection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeMailProvider;

beforeEach(function () {
    $this->provider = new FakeMailProvider();
    app(MailProviderFactory::class)->extend(MailProviderType::Imap, fn () => $this->provider);

    $this->tenant = Tenant::factory()->create();
    $this->ana = User::factory()->for($this->tenant)->agent()->create(['name' => 'Ana']);

    [$this->email, $this->reply] = inTenant($this->tenant, function () {
        $email = Email::factory()->create([
            'mail_connection_id' => MailConnection::factory()->create(['status' => MailConnectionStatus::Active]),
            'from_email' => 'cliente@cliente.test',
            'subject' => '¿Dónde está mi pedido #45821?',
            'message_id' => 'pedido-45821@cliente.test',
            'status' => EmailStatus::Processing,
        ]);
        $reply = draftReply($email, ['needs_human' => true, 'needs_human_reason' => 'Confianza baja.']);
        $email->transitionTo(EmailStatus::AiReview, 'Respuesta propuesta por la IA.');
        $email->transitionTo(EmailStatus::HumanReview, 'Confianza baja.');

        return [$email, $reply];
    });
});

function review(): HumanReviewService
{
    return app(HumanReviewService::class);
}

/** Ejecuta en el tenant de la prueba. */
function asTenant(callable $callback): mixed
{
    return inTenant(test()->tenant, $callback);
}

it('approves the proposal as it is and sends it on behalf of the person', function () {
    $this->travel(5)->minutes();

    $reply = asTenant(fn () => review()->approve($this->reply, $this->ana));

    expect($reply->status)->toBe(EmailReplyStatus::Sent)
        ->and($reply->approved_by)->toBe($this->ana->id)
        ->and($reply->sent_automatically)->toBeFalse()
        ->and($reply->wasEdited())->toBeFalse()
        ->and($this->email->fresh()->status)->toBe(EmailStatus::Sent)
        ->and($this->provider->sent)->toHaveCount(1)
        ->and($this->provider->sent[0]->textBody)->toBe($this->reply->body)
        // Una persona la aprobó: no es una respuesta automática.
        ->and($this->provider->sent[0]->autoSubmitted)->toBeFalse();

    $review = asTenant(fn () => $this->email->reviews()->sole());
    $approval = asTenant(fn () => $this->email->statusChanges()->where('to_status', EmailStatus::Approved)->sole());

    expect($review->decision)->toBe(ReviewDecision::Approved)
        ->and($review->user_id)->toBe($this->ana->id)
        ->and($review->email_reply_id)->toBe($this->reply->id)
        ->and($review->edited)->toBeFalse()
        ->and($review->waited_seconds)->toBeGreaterThanOrEqual(300)
        ->and($approval->user_id)->toBe($this->ana->id)
        ->and($approval->reason)->toBe('Respuesta de la IA aprobada por Ana');
});

it('keeps the original text of the ai when the person edits it', function () {
    $aiText = $this->reply->body;
    $edited = "Hola:\r\n\r\nTu pedido #45821 llega mañana.\r\n\r\nUn saludo,\r\nAna  \n";

    $reply = asTenant(fn () => review()->approve($this->reply, $this->ana, $edited));

    expect($reply->body)->toBe("Hola:\n\nTu pedido #45821 llega mañana.\n\nUn saludo,\nAna")
        ->and($reply->original_body)->toBe($aiText)
        ->and($reply->edited_by)->toBe($this->ana->id)
        ->and($reply->edited_at)->not->toBeNull()
        ->and($this->provider->sent[0]->textBody)->toBe($reply->body)
        ->and(asTenant(fn () => $this->email->reviews()->sole()->edited))->toBeTrue()
        ->and(asTenant(fn () => $this->email->statusChanges()->where('to_status', EmailStatus::Approved)->value('reason')))
        ->toBe('Respuesta de la IA aprobada con cambios por Ana');
});

it('does not count line endings or trailing spaces as an edit', function () {
    $sameText = str_replace("\n", "\r\n", $this->reply->body) . "   \n\n";

    $reply = asTenant(fn () => review()->approve($this->reply, $this->ana, $sameText));

    expect($reply->wasEdited())->toBeFalse()
        ->and($reply->body)->toBe($this->reply->body);
});

it('discards the other drafts when one is approved', function () {
    $older = asTenant(fn () => draftReply($this->email, ['body' => 'Propuesta anterior']));

    asTenant(fn () => review()->approve($this->reply, $this->ana));

    $older->refresh();

    expect($older->status)->toBe(EmailReplyStatus::Rejected)
        ->and($older->rejected_by)->toBe($this->ana->id)
        ->and($older->rejection_reason)->toBe('Se aprobó otra respuesta.');
});

it('rejects the proposal and leaves the email waiting for a person', function () {
    $reply = asTenant(fn () => review()->reject($this->reply, $this->ana, 'Inventa la fecha de entrega.'));

    $review = asTenant(fn () => $this->email->reviews()->sole());

    expect($reply->status)->toBe(EmailReplyStatus::Rejected)
        ->and($reply->rejected_by)->toBe($this->ana->id)
        ->and($reply->rejected_at)->not->toBeNull()
        ->and($reply->rejection_reason)->toBe('Inventa la fecha de entrega.')
        ->and($this->email->fresh()->status)->toBe(EmailStatus::HumanReview)
        ->and($this->provider->sent)->toBe([])
        ->and($review->decision)->toBe(ReviewDecision::Rejected)
        ->and($review->reason)->toBe('Inventa la fecha de entrega.');
});

it('sends a reply written by the person and discards the proposal', function () {
    $reply = asTenant(fn () => review()->reply($this->email, $this->ana, "Hola:\n\nLo miro y te digo.\n\nAna"));

    expect($reply->status)->toBe(EmailReplyStatus::Sent)
        ->and($reply->created_by)->toBe($this->ana->id)
        ->and($reply->agent_run_id)->toBeNull()
        ->and($reply->approved_by)->toBe($this->ana->id)
        ->and($reply->sent_automatically)->toBeFalse()
        ->and($this->provider->sent[0]->textBody)->toBe("Hola:\n\nLo miro y te digo.\n\nAna")
        ->and($this->reply->fresh()->status)->toBe(EmailReplyStatus::Rejected)
        ->and($this->reply->fresh()->rejection_reason)->toBe('Sustituida por una respuesta escrita por Ana.')
        ->and(asTenant(fn () => $this->email->reviews()->sole()->decision))->toBe(ReviewDecision::Replied);
});

it('lets a person answer an email whose sending failed', function () {
    asTenant(fn () => $this->email->transitionTo(EmailStatus::Approved, 'Aprobada'));
    asTenant(fn () => $this->email->transitionTo(EmailStatus::Failed, 'No se pudo enviar la respuesta: 550'));

    asTenant(fn () => review()->reply($this->email, $this->ana, 'Respuesta corregida'));

    $path = asTenant(fn () => $this->email->statusChanges()->pluck('to_status')->map->value->all());

    expect($this->email->fresh()->status)->toBe(EmailStatus::Sent)
        ->and(array_slice($path, -4))->toBe(['failed', 'human_review', 'approved', 'sent']);
});

it('closes the case without replying', function () {
    asTenant(fn () => review()->close($this->email, $this->ana, 'Ya le llamamos por teléfono.'));

    $change = asTenant(fn () => $this->email->statusChanges()->reorder('id', 'desc')->first());

    expect($this->email->fresh()->status)->toBe(EmailStatus::Ignored)
        ->and($change->user_id)->toBe($this->ana->id)
        ->and($change->reason)->toBe('Cerrado sin responder por Ana: Ya le llamamos por teléfono.')
        ->and($this->reply->fresh()->status)->toBe(EmailReplyStatus::Rejected)
        ->and($this->provider->sent)->toBe([])
        ->and(asTenant(fn () => $this->email->reviews()->sole()->decision))->toBe(ReviewDecision::Closed);
});

it('sends the email back to the ai and discards the old proposal', function () {
    Queue::fake();

    asTenant(fn () => review()->reprocess($this->email, $this->ana));

    expect($this->email->fresh()->status)->toBe(EmailStatus::Processing)
        ->and($this->reply->fresh()->status)->toBe(EmailReplyStatus::Rejected)
        ->and(asTenant(fn () => $this->email->reviews()->sole()->decision))->toBe(ReviewDecision::Reprocessed);

    Queue::assertPushed(ProcessEmailJob::class, fn (ProcessEmailJob $job) => $job->emailId === $this->email->id && $job->reprocess);
});

it('never reprocesses an email whose reply was already sent or attempted', function () {
    Queue::fake();
    asTenant(fn () => draftReply($this->email)->forceFill(['status' => EmailReplyStatus::Failed])->save());

    expect(fn () => asTenant(fn () => review()->reprocess($this->email, $this->ana)))
        ->toThrow(ReviewException::class, 'puede haber llegado');

    expect($this->email->fresh()->status)->toBe(EmailStatus::HumanReview);
    Queue::assertNothingPushed();
});

it('refuses decisions on emails that are not waiting for a person', function (EmailStatus $status) {
    asTenant(fn () => Email::query()->whereKey($this->email->id)->update(['status' => $status]));

    expect(fn () => asTenant(fn () => review()->approve($this->reply, $this->ana)))->toThrow(ReviewException::class, 'ya no está esperando')
        ->and(fn () => asTenant(fn () => review()->reply($this->email, $this->ana, 'Hola')))->toThrow(ReviewException::class)
        ->and(fn () => asTenant(fn () => review()->close($this->email, $this->ana)))->toThrow(ReviewException::class)
        ->and($this->provider->sent)->toBe([])
        ->and(asTenant(fn () => $this->email->reviews()->count()))->toBe(0);
})->with([
    'procesándose' => [EmailStatus::Processing],
    'ya aprobado' => [EmailStatus::Approved],
    'enviado' => [EmailStatus::Sent],
]);

it('refuses to approve twice the same reply (another person was faster)', function () {
    $bea = User::factory()->for($this->tenant)->create(['name' => 'Bea']);
    $stale = asTenant(fn () => EmailReply::find($this->reply->id));

    asTenant(fn () => review()->reject($this->reply, $bea));

    expect(fn () => asTenant(fn () => review()->approve($stale, $this->ana)))->toThrow(ReviewException::class, 'ya no es un borrador')
        ->and($this->provider->sent)->toBe([])
        ->and(asTenant(fn () => $this->email->reviews()->count()))->toBe(1);
});

it('assigns and releases a case', function () {
    $email = asTenant(fn () => review()->assign($this->email, $this->ana));

    expect($email->fresh()->assigned_to)->toBe($this->ana->id)
        ->and($email->fresh()->assigned_at)->not->toBeNull();

    asTenant(fn () => review()->assign($this->email, null));

    expect($this->email->fresh()->assigned_to)->toBeNull()
        ->and($this->email->fresh()->assigned_at)->toBeNull();
});

it('must run inside the tenant of the email', function () {
    $other = Tenant::factory()->create();

    expect(fn () => inTenant($other, fn () => review()->close($this->email, $this->ana)))->toThrow(LogicException::class);
});
