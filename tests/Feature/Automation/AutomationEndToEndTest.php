<?php

use App\AI\Remote\Contracts\EventStream;
use App\Automation\AutomationMode;
use App\Mail\Data\MailMessage;
use App\Mail\Enums\EmailStatus;
use App\Mail\Enums\MailConnectionStatus;
use App\Models\Email;
use App\Models\EmailStatusChange;
use App\Models\MailConnection;
use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email as MimeEmail;
use Tests\Support\GreenMail;

/*
 * El MVP completo (sección 17) contra un servidor de correo real
 * (GreenMail): el cliente escribe → mail:sync → ingesta IMAP → evento →
 * ProcessEmailJob → email.received.v1 para ai-service → su resultado
 * (simulado) → reglas → SendReplyJob → SMTP → la respuesta llega al buzón del
 * cliente en su hilo. Las colas son sync en los tests.
 */

beforeEach(function () {
    config(['mail_connections.allow_private_hosts' => true]);
    Storage::fake('local');
    $this->streams = app(EventStream::class);

    $this->tenant = Tenant::factory()->create();
    $this->tenant->configureAutomation(AutomationMode::Auto);
    $this->support = GreenMail::createAccount();
    $this->customer = GreenMail::createAccount();

    $connection = inTenant($this->tenant, fn () => MailConnection::factory()->create([
        'name' => 'Soporte Acme',
        'email' => $this->support,
        'credentials' => GreenMail::credentials($this->support),
    ]));
    $connection->forceFill(['status' => MailConnectionStatus::Active])->save();

    $this->artisan('mail:sync')->assertSuccessful(); // primera pasada: punto de partida
});

/** El cliente escribe al buzón de soporte. */
function customerWrites(string $subject, string $messageId, ?Closure $customize = null): void
{
    $email = (new MimeEmail())
        ->from(test()->customer)
        ->to(test()->support)
        ->subject($subject)
        ->text('Hola, vivo en Las Palmas. ¿Hacéis envíos a Canarias? ¿Cuánto cuesta?');
    $email->getHeaders()->addIdHeader('Message-ID', $messageId);

    if ($customize) {
        $customize($email);
    }

    GreenMail::append(test()->support, $email);
}

/** ai-service responde al email que se le publicó (resultado simulado según el contrato). */
function aiServiceAnswers(string $messageId, array $overrides = []): Email
{
    $email = inTenant(test()->tenant, fn () => Email::where('message_id', $messageId)->sole());
    expect($email->status)->toBe(EmailStatus::Processing); // publicado, esperando a ai-service
    applyAiResult(processedEvent($email, $overrides));

    return $email->fresh();
}

/** @return list<MailMessage> */
function customerInbox(): array
{
    return GreenMail::provider(test()->customer)->messages('INBOX');
}

it('answers a customer on its own through a real mailbox, in the same thread', function () {
    customerWrites('Envíos a Canarias', 'consulta-canarias@cliente.test');

    $this->artisan('mail:sync')->assertSuccessful();
    $email = aiServiceAnswers('consulta-canarias@cliente.test', [
        'draft' => "Hola:\n\nSí, enviamos a Canarias, de 5 a 7 días laborables.\n\nUn saludo,\nEl equipo de atención al cliente",
    ]);
    $path = inTenant($this->tenant, fn () => $email->statusChanges()->get()->map(fn (EmailStatusChange $change) => $change->to_status->value)->all());

    expect($email->status)->toBe(EmailStatus::Sent)
        ->and($path)->toBe(['processing', 'ai_review', 'approved', 'sent']);

    $inbox = customerInbox();

    expect($inbox)->toHaveCount(1)
        ->and($inbox[0]->subject)->toBe('Re: Envíos a Canarias')
        ->and($inbox[0]->from->email)->toBe($this->support)
        ->and($inbox[0]->from->name)->toBe('Soporte Acme')
        ->and($inbox[0]->inReplyTo)->toBe('consulta-canarias@cliente.test')
        ->and($inbox[0]->references)->toBe(['consulta-canarias@cliente.test'])
        ->and($inbox[0]->textBody)->toContain('de 5 a 7 días laborables')
        // La respuesta lleva Auto-Submitted: nuestro propio detector la reconoce como automática.
        ->and($inbox[0]->automatedReason)->toContain('auto-replied');

    $reply = inTenant($this->tenant, fn () => $email->replies()->sole());
    expect($reply->sent_message_id)->toBe($inbox[0]->messageId);
})->group('greenmail');

it('does not fall into a loop with the customer auto-responder', function () {
    customerWrites('Envíos a Canarias', 'consulta-canarias@cliente.test');
    $this->artisan('mail:sync')->assertSuccessful();
    aiServiceAnswers('consulta-canarias@cliente.test');

    // El cliente está de vacaciones: su sistema contesta solo a nuestra respuesta.
    customerWrites('Fuera de la oficina: Re: Envíos a Canarias', 'vacaciones@cliente.test', function (MimeEmail $email) {
        $email->text('Estoy fuera hasta el lunes.');
        $email->getHeaders()->addTextHeader('Auto-Submitted', 'auto-replied');
    });
    $this->artisan('mail:sync')->assertSuccessful();

    $autoReply = inTenant($this->tenant, fn () => Email::where('message_id', 'vacaciones@cliente.test')->sole());

    expect($autoReply->status)->toBe(EmailStatus::Ignored)
        ->and($this->streams->entries['email.received.v1'])->toHaveCount(1) // solo el primer email
        ->and(customerInbox())->toHaveCount(1);
})->group('greenmail');

it('keeps the reply for a person in draft mode', function () {
    $this->tenant->configureAutomation(AutomationMode::Draft);
    customerWrites('Envíos a Canarias', 'consulta-canarias@cliente.test');

    $this->artisan('mail:sync')->assertSuccessful();

    expect(aiServiceAnswers('consulta-canarias@cliente.test')->status)->toBe(EmailStatus::HumanReview)
        ->and(customerInbox())->toBe([]);
})->group('greenmail');
