<?php

use App\AI\Agent\Enums\EmailReplyStatus;
use App\Automation\ReplySender;
use App\Automation\SendReplyJob;
use App\Mail\Data\OutgoingMessage;
use App\Mail\Enums\EmailStatus;
use App\Mail\Enums\MailConnectionStatus;
use App\Mail\Enums\MailProviderType;
use App\Mail\Enums\RecipientType;
use App\Mail\Exceptions\MailAuthenticationException;
use App\Mail\Exceptions\MailConnectionException;
use App\Mail\Exceptions\MailProviderException;
use App\Mail\MailProviderFactory;
use App\Mail\Support\MimeMessageBuilder;
use App\Mail\Data\MailAddress;
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
    [$this->email, $this->reply] = inTenant($this->tenant, function () {
        $email = Email::factory()->create([
            'mail_connection_id' => MailConnection::factory()->create(['status' => MailConnectionStatus::Active]),
            'from_email' => 'cliente@cliente.test',
            'from_name' => 'Cliente Pérez',
            'subject' => '¿Dónde está mi pedido #45821?',
            'message_id' => 'pedido-45821@cliente.test',
            'references' => ['inicio@cliente.test'],
            'status' => EmailStatus::AiReview,
        ]);

        return [$email, draftReply($email)];
    });
});

/** Aprueba (por las reglas o por $user) y envía en el momento, sin cola. */
function approveAndSend(?User $user = null): EmailReply
{
    Queue::fake();

    return inTenant(test()->tenant, function () use ($user) {
        $sender = app(ReplySender::class);
        $sender->approve(test()->reply, 'Respuesta segura', $user);
        $sender->send(test()->reply);

        return test()->reply->fresh();
    });
}

function sentMessage(): OutgoingMessage
{
    expect(test()->provider->sent)->toHaveCount(1);

    return test()->provider->sent[0];
}

it('approves a draft and queues its sending', function () {
    Queue::fake();

    inTenant($this->tenant, fn () => app(ReplySender::class)->approve($this->reply, 'Respuesta segura de una intención permitida'));

    $reply = $this->reply->fresh();
    $change = inTenant($this->tenant, fn () => $this->email->statusChanges()->sole());

    expect($reply->status)->toBe(EmailReplyStatus::Approved)
        ->and($reply->approved_by)->toBeNull()
        ->and($reply->approved_at)->not->toBeNull()
        ->and($reply->sent_automatically)->toBeTrue()
        ->and($this->email->fresh()->status)->toBe(EmailStatus::Approved)
        ->and($change->reason)->toBe('Respuesta segura de una intención permitida');

    Queue::assertPushedOn('mail', SendReplyJob::class, fn (SendReplyJob $job) => $job->replyId === $this->reply->id && $job->tenantId === $this->tenant->id);
});

it('records the person who approves a reply', function () {
    $user = User::factory()->for($this->tenant)->create();

    $reply = approveAndSend($user);

    expect($reply->approved_by)->toBe($user->id)
        ->and($reply->sent_automatically)->toBeFalse()
        ->and(sentMessage()->autoSubmitted)->toBeFalse()
        ->and(inTenant($this->tenant, fn () => $this->email->statusChanges()->first()->user_id))->toBe($user->id);
});

it('only approves drafts', function () {
    inTenant($this->tenant, fn () => $this->reply->forceFill(['status' => EmailReplyStatus::Rejected])->save());

    expect(fn () => inTenant($this->tenant, fn () => app(ReplySender::class)->approve($this->reply, 'x')))
        ->toThrow(LogicException::class, 'ya no es un borrador');

    expect($this->email->fresh()->status)->toBe(EmailStatus::AiReview);
});

it('sends the reply in the thread of the original email', function () {
    $reply = approveAndSend();
    $message = sentMessage();

    expect($message->to)->toHaveCount(1)
        ->and($message->to[0]->email)->toBe('cliente@cliente.test')
        ->and($message->to[0]->name)->toBe('Cliente Pérez')
        ->and($message->subject)->toBe('Re: ¿Dónde está mi pedido #45821?')
        ->and($message->textBody)->toContain('salió el 29 de septiembre')
        ->and($message->inReplyTo)->toBe('pedido-45821@cliente.test')
        ->and($message->references)->toBe(['inicio@cliente.test', 'pedido-45821@cliente.test'])
        ->and($message->autoSubmitted)->toBeTrue()
        ->and($message->cc)->toBe([]);

    $change = inTenant($this->tenant, fn () => $this->email->statusChanges()->get()->last());

    expect($reply->status)->toBe(EmailReplyStatus::Sent)
        ->and($reply->sent_to)->toBe('cliente@cliente.test')
        ->and($reply->sent_message_id)->toBe('fake-1@fake.test')
        ->and($reply->sent_at)->not->toBeNull()
        ->and($this->email->fresh()->status)->toBe(EmailStatus::Sent)
        ->and($change->reason)->toBe('Respuesta enviada automáticamente a cliente@cliente.test');
});

it('answers to the reply-to address when there is one', function () {
    inTenant($this->tenant, fn () => $this->email->recipients()->create([
        'type' => RecipientType::ReplyTo, 'email' => 'respuestas@cliente.test', 'name' => null, 'position' => 0,
    ]));

    $reply = approveAndSend();

    expect(sentMessage()->to[0]->email)->toBe('respuestas@cliente.test')
        ->and($reply->sent_to)->toBe('respuestas@cliente.test');
});

it('keeps an existing reply prefix', function (string $subject, string $expected) {
    inTenant($this->tenant, fn () => $this->email->forceFill(['subject' => $subject])->save());

    approveAndSend();

    expect(sentMessage()->subject)->toBe($expected);
})->with([
    ['RE: Pedido', 'RE: Pedido'],
    ['Aw: Bestellung', 'Aw: Bestellung'],
    ['', 'Re: (sin asunto)'],
    ['Reembolso', 'Re: Reembolso'],
]);

it('never sends the same reply twice', function () {
    approveAndSend();

    inTenant($this->tenant, fn () => app(ReplySender::class)->send($this->reply->fresh()));

    expect($this->provider->sent)->toHaveCount(1);
});

it('does not send if the email is no longer approved', function () {
    Queue::fake();
    inTenant($this->tenant, function () {
        app(ReplySender::class)->approve($this->reply, 'ok');
        Email::whereKey($this->email->id)->update(['status' => EmailStatus::HumanReview]);
        app(ReplySender::class)->send($this->reply->fresh());
    });

    expect($this->provider->sent)->toBe([])
        ->and($this->reply->fresh()->status)->toBe(EmailReplyStatus::Approved);
});

it('releases the reply for a retry when the server cannot be reached', function () {
    Queue::fake();
    $this->provider->failSendWith = new MailConnectionException('No se pudo conectar con smtp.empresa.test.');

    expect(fn () => inTenant($this->tenant, function () {
        app(ReplySender::class)->approve($this->reply, 'ok');
        app(ReplySender::class)->send($this->reply);
    }))->toThrow(MailConnectionException::class);

    expect($this->reply->fresh()->status)->toBe(EmailReplyStatus::Approved)
        ->and($this->email->fresh()->status)->toBe(EmailStatus::Approved);

    // El reintento la envía.
    $this->provider->failSendWith = null;
    inTenant($this->tenant, fn () => app(ReplySender::class)->send($this->reply->fresh()));

    expect($this->reply->fresh()->status)->toBe(EmailReplyStatus::Sent);
});

it('fails without retrying when the server rejects the email', function (Throwable $error, string $message) {
    $this->provider->failSendWith = $error;

    $reply = approveAndSend();
    $change = inTenant($this->tenant, fn () => $this->email->statusChanges()->get()->last());

    expect($reply->status)->toBe(EmailReplyStatus::Failed)
        ->and($reply->send_error)->toBe($message)
        ->and($this->email->fresh()->status)->toBe(EmailStatus::Failed)
        ->and($change->reason)->toBe("No se pudo enviar la respuesta: {$message}");
})->with([
    'destinatario rechazado' => [new MailProviderException('El servidor rechazó el envío (550).'), 'El servidor rechazó el envío (550).'],
    'credenciales' => [new MailAuthenticationException('El servidor rechazó el usuario o la contraseña.'), 'El servidor rechazó el usuario o la contraseña.'],
]);

it('marks the mailbox as failing when its credentials are rejected', function () {
    $this->provider->failSendWith = new MailAuthenticationException('El servidor rechazó el usuario o la contraseña.');

    approveAndSend();

    expect($this->email->mailConnection()->withoutGlobalScopes()->first())
        ->status->toBe(MailConnectionStatus::Failing)
        ->last_error->toBe('El servidor rechazó el usuario o la contraseña.');
});

it('does not send through a disabled mailbox', function () {
    inTenant($this->tenant, fn () => $this->email->mailConnection->forceFill(['status' => MailConnectionStatus::Disabled])->save());

    $reply = approveAndSend();

    expect($this->provider->sent)->toBe([])
        ->and($reply->status)->toBe(EmailReplyStatus::Failed)
        ->and($reply->send_error)->toContain('desactivado');
});

it('does not retry when the outcome is unknown', function () {
    Queue::fake();
    $this->provider->failSendWith = new RuntimeException('Fallo inesperado');

    expect(fn () => inTenant($this->tenant, function () {
        app(ReplySender::class)->approve($this->reply, 'ok');
        app(ReplySender::class)->send($this->reply);
    }))->toThrow(RuntimeException::class);

    expect($this->reply->fresh())
        ->status->toBe(EmailReplyStatus::Failed)
        ->send_error->toContain('revisa si llegó');
});

it('sends from the queue job, and gives up after the last retry', function () {
    inTenant($this->tenant, fn () => app(ReplySender::class)->approve($this->reply, 'ok'));

    // Cola sync en tests: el job ya se ha ejecutado.
    expect($this->reply->fresh()->status)->toBe(EmailReplyStatus::Sent);

    $other = inTenant($this->tenant, function () {
        $email = Email::factory()->create(['status' => EmailStatus::Approved]);

        return tap(draftReply($email), fn (EmailReply $reply) => $reply->forceFill(['status' => EmailReplyStatus::Approved])->save());
    });

    (new SendReplyJob($this->tenant->id, $other->id))->failed(new MailConnectionException('Sin conexión tras 3 intentos.'));

    expect($other->fresh())
        ->status->toBe(EmailReplyStatus::Failed)
        ->send_error->toBe('Sin conexión tras 3 intentos.');
});

it('marks automatic replies so other robots do not answer them', function () {
    $message = fn (bool $auto) => MimeMessageBuilder::build(
        new OutgoingMessage([new MailAddress('cliente@cliente.test')], 'Re: Pedido', 'Hola', autoSubmitted: $auto),
        new MailAddress('soporte@empresa.test'),
    )->toString();

    expect($message(true))->toContain('Auto-Submitted: auto-replied')
        ->and($message(false))->not->toContain('Auto-Submitted');
});
