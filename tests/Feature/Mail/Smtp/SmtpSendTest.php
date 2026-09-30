<?php

use App\Mail\Data\MailAddress;
use App\Mail\Data\MailMessage;
use App\Mail\Data\OutgoingAttachment;
use App\Mail\Data\OutgoingMessage;
use App\Mail\Exceptions\MailAuthenticationException;
use Tests\Support\GreenMail;

/*
 * Envío por SMTP a GreenMail y verificación leyendo el buzón del
 * destinatario por IMAP: lo que se comprueba es lo que llega de verdad.
 */

beforeEach(function () {
    $this->support = GreenMail::createAccount();
    $this->customer = GreenMail::createAccount();
    $this->boss = GreenMail::createAccount();
});

/** Espera a que GreenMail entregue el correo (la entrega es asíncrona). */
function receivedBy(string $account, int $expected = 1): array
{
    for ($i = 0; $i < 20; $i++) {
        $messages = GreenMail::provider($account)->messages('INBOX');

        if (count($messages) >= $expected) {
            return $messages;
        }

        usleep(250_000);
    }

    return GreenMail::provider($account)->messages('INBOX');
}

it('sends an email that arrives with all its content', function () {
    $sent = GreenMail::provider($this->support, name: 'Soporte Acme')->send(new OutgoingMessage(
        to: [new MailAddress($this->customer, 'Cliente Pérez')],
        subject: 'Re: Pedido #45821 — enviado ✅',
        textBody: "Hola,\ntu pedido #45821 está en camino.",
        htmlBody: '<p>Hola, tu pedido <b>#45821</b> está en camino.</p>',
        cc: [new MailAddress($this->boss)],
    ));

    [$received] = receivedBy($this->customer);

    expect($received)->toBeInstanceOf(MailMessage::class)
        ->subject->toBe('Re: Pedido #45821 — enviado ✅')
        ->messageId->toBe($sent->messageId)
        ->and($received->from->email)->toBe($this->support)
        ->and($received->from->name)->toBe('Soporte Acme')
        ->and($received->to[0]->name)->toBe('Cliente Pérez')
        ->and($received->cc[0]->email)->toBe($this->boss)
        ->and($received->textBody)->toContain('está en camino')
        ->and($received->htmlBody)->toContain('<b>#45821</b>')
        ->and($sent->messageId)->not->toBeEmpty()->not->toContain('<');

    expect(receivedBy($this->boss))->toHaveCount(1);
})->group('greenmail');

it('keeps the reply in the same thread', function () {
    GreenMail::provider($this->support)->send(new OutgoingMessage(
        to: [new MailAddress($this->customer)],
        subject: 'Re: Consulta',
        textBody: 'Respuesta',
        inReplyTo: 'original@cliente.test',
        references: ['primero@cliente.test', 'original@cliente.test'],
    ));

    [$received] = receivedBy($this->customer);

    expect($received->inReplyTo)->toBe('original@cliente.test')
        ->and($received->references)->toBe(['primero@cliente.test', 'original@cliente.test']);
})->group('greenmail');

it('sends attachments intact', function () {
    $pdf = "%PDF-1.4\x00\xff binario " . random_bytes(1024);

    GreenMail::provider($this->support)->send(new OutgoingMessage(
        to: [new MailAddress($this->customer)],
        subject: 'Factura',
        textBody: 'Adjunto la factura.',
        attachments: [new OutgoingAttachment('factura ñ.pdf', $pdf, 'application/pdf')],
    ));

    [$received] = receivedBy($this->customer);
    $provider = GreenMail::provider($this->customer);

    expect($received->attachments)->toHaveCount(1)
        ->and($received->attachments[0]->filename)->toBe('factura ñ.pdf')
        ->and($provider->downloadAttachment('INBOX', $received->id, $received->attachments[0]->id)->content)->toBe($pdf);
})->group('greenmail');

it('delivers bcc recipients without exposing them', function () {
    GreenMail::provider($this->support)->send(new OutgoingMessage(
        to: [new MailAddress($this->customer)],
        subject: 'Con copia oculta',
        textBody: 'x',
        bcc: [new MailAddress($this->boss)],
    ));

    [$toCustomer] = receivedBy($this->customer);
    [$toBoss] = receivedBy($this->boss);

    expect($toBoss->subject)->toBe('Con copia oculta')
        ->and($toCustomer->cc)->toBe([])
        ->and(json_encode([$toCustomer->to, $toCustomer->cc]))->not->toContain($this->boss);
})->group('greenmail');

it('fails with an authentication error when the smtp password is wrong', function () {
    GreenMail::provider($this->support, 'clave-incorrecta')->send(new OutgoingMessage(
        to: [new MailAddress($this->customer)],
        subject: 'x',
        textBody: 'x',
    ));
})->group('greenmail')->throws(MailAuthenticationException::class);
