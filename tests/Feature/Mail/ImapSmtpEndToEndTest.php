<?php

use App\Mail\Contracts\MailProvider;
use App\Mail\Credentials\ImapSmtpCredentials;
use App\Mail\Data\MailAddress;
use App\Mail\Data\OutgoingAttachment;
use App\Mail\Data\OutgoingMessage;
use App\Mail\Exceptions\MailProviderException;
use App\Mail\MailProviderFactory;
use App\Models\MailConnection;
use App\Models\Tenant;
use Tests\Support\GreenMail;

/*
 * Flujo completo tal como lo usará la plataforma: conexión guardada en BD
 * (credenciales cifradas) → factory → proveedor IMAP/SMTP real (GreenMail).
 */

beforeEach(function () {
    config(['mail_connections.allow_private_hosts' => true]);

    $this->tenant = Tenant::factory()->create();
    $this->supportEmail = GreenMail::createAccount('clave-soporte');
    $this->customerEmail = GreenMail::createAccount();

    $this->connection = inTenant($this->tenant, fn () => MailConnection::factory()->create([
        'name' => 'Soporte Acme',
        'email' => $this->supportEmail,
        'credentials' => GreenMail::credentials($this->supportEmail, 'clave-soporte'),
    ]));
});

/** Proveedor de la conexión, recargada de BD como en un job real. */
function supportProvider(Tenant $tenant, MailConnection $connection): MailProvider
{
    return inTenant($tenant, fn () => app(MailProviderFactory::class)->make(MailConnection::findOrFail($connection->id)));
}

/** Espera activa a que GreenMail entregue (la entrega SMTP es asíncrona). */
function waitForNew(MailProvider $provider, string $cursor, int $expected = 1): array
{
    $messages = [];

    for ($i = 0; $i < 20 && count($messages) < $expected; $i++) {
        $result = $provider->newMessages('INBOX', $cursor);
        $messages = [...$messages, ...$result->messages];
        $cursor = $result->cursor;
        usleep(250_000);
    }

    return [$messages, $cursor];
}

it('receives, reads and answers an email in the same thread', function () {
    $support = supportProvider($this->tenant, $this->connection);
    $cursor = $support->newMessages('INBOX', null)->cursor;

    // 1. El cliente escribe al soporte (SMTP real) con un adjunto.
    $question = GreenMail::provider($this->customerEmail, name: 'Cliente Pérez')->send(new OutgoingMessage(
        to: [new MailAddress($this->supportEmail)],
        subject: '¿Dónde está mi pedido #45821?',
        textBody: 'Adjunto el justificante de pago.',
        attachments: [new OutgoingAttachment('pago.pdf', '%PDF pago', 'application/pdf')],
    ));

    // 2. El soporte lo detecta como nuevo.
    [$new, $cursor] = waitForNew($support, $cursor);
    expect($new)->toHaveCount(1);
    $incoming = $new[0];

    // 3. Lo lee y descarga el adjunto.
    expect($incoming)
        ->subject->toBe('¿Dónde está mi pedido #45821?')
        ->messageId->toBe($question->messageId)
        ->and($incoming->from->name)->toBe('Cliente Pérez')
        ->and($support->downloadAttachment('INBOX', $incoming->id, $incoming->attachments[0]->id)->content)->toBe('%PDF pago');

    // 4. Responde en el mismo hilo.
    $answer = $support->send(new OutgoingMessage(
        to: [$incoming->from],
        subject: 'Re: ' . $incoming->subject,
        textBody: 'Tu pedido sale hoy.',
        inReplyTo: $incoming->messageId,
        references: [...$incoming->references, $incoming->messageId],
    ));

    // 5. El cliente recibe la respuesta enlazada a su mensaje.
    $customer = GreenMail::provider($this->customerEmail);
    $reply = null;
    for ($i = 0; $i < 20 && $reply === null; $i++) {
        $reply = collect($customer->messages('INBOX'))->firstWhere('messageId', $answer->messageId);
        $reply ?? usleep(250_000);
    }

    expect($reply)->not->toBeNull()
        ->and($reply->from->email)->toBe($this->supportEmail)
        ->and($reply->from->name)->toBe('Soporte Acme')
        ->and($reply->inReplyTo)->toBe($question->messageId)
        ->and($reply->references)->toContain($question->messageId);

    // Y no vuelve a aparecer como nuevo en el soporte.
    expect($support->newMessages('INBOX', $cursor)->messages)->toBe([]);
})->group('greenmail');

it('never reveals the password in any error of any operation', function () {
    $secret = 'contraseña-que-no-debe-aparecer';
    $credentials = ImapSmtpCredentials::fromArray(array_merge(
        GreenMail::credentials($this->supportEmail)->toArray(),
        ['imap_password' => $secret, 'smtp_password' => $secret],
    ));
    inTenant($this->tenant, fn () => $this->connection->forceFill(['credentials' => $credentials])->save());

    $provider = supportProvider($this->tenant, $this->connection);

    $operations = [
        'folders' => fn () => $provider->folders(),
        'messages' => fn () => $provider->messages('INBOX'),
        'message' => fn () => $provider->message('INBOX', '1'),
        'attachment' => fn () => $provider->downloadAttachment('INBOX', '1', '0'),
        'newMessages' => fn () => $provider->newMessages('INBOX', null),
        'send' => fn () => $provider->send(new OutgoingMessage([new MailAddress($this->customerEmail)], 'x', 'x')),
    ];

    foreach ($operations as $name => $operation) {
        try {
            $operation();
            $this->fail("{$name} debería haber fallado con la contraseña incorrecta.");
        } catch (MailProviderException $e) {
            $chain = '';
            for ($current = $e; $current; $current = $current->getPrevious()) {
                $chain .= $current->getMessage() . "\n";
            }

            expect($chain)->not->toContain($secret);
        }
    }

    $result = $provider->testConnection();
    expect($result->ok())->toBeFalse()
        ->and($result->incomingError . $result->outgoingError)->not->toContain($secret);
})->group('greenmail');
