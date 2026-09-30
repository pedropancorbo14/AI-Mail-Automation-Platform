<?php

use App\Mail\Data\MailMessage;
use App\Mail\Exceptions\MailNotFoundException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\Support\GreenMail;

/*
 * Lectura de mensajes contra GreenMail real. Los correos se meten con IMAP
 * APPEND (como si hubieran llegado).
 */

beforeEach(function () {
    $this->email = GreenMail::createAccount();
});

function customerEmail(string $subject = 'Consulta', array $extra = []): Email
{
    $message = (new Email())
        ->from(new Address('cliente@cliente.test', 'Cliente Pérez'))
        ->to($extra['to'] ?? 'soporte@acme.test')
        ->subject($subject)
        ->text("Hola,\n¿dónde está mi pedido #45821?")
        ->html('<p>Hola, ¿dónde está mi pedido <b>#45821</b>?</p>')
        ->date(new DateTimeImmutable($extra['date'] ?? '2026-09-30 10:00:00'));

    $message->getHeaders()->addIdHeader('Message-ID', $extra['message_id'] ?? uniqid('msg-', true) . '@cliente.test');

    return $message;
}

it('reads a message with all its data', function () {
    $message = customerEmail('Pedido #45821 — ¿dónde está? 📦', ['message_id' => 'abc-123@cliente.test'])
        ->cc(new Address('jefe@cliente.test', 'Jefe'))
        ->replyTo('respuestas@cliente.test');
    $message->getHeaders()->addIdHeader('In-Reply-To', 'anterior@acme.test');
    $message->getHeaders()->addIdHeader('References', ['primero@acme.test', 'anterior@acme.test']);

    $uid = GreenMail::append($this->email, $message);

    $read = GreenMail::provider($this->email)->message('INBOX', (string) $uid);

    expect($read)->toBeInstanceOf(MailMessage::class)
        ->id->toBe((string) $uid)
        ->folder->toBe('INBOX')
        ->messageId->toBe('abc-123@cliente.test')
        ->subject->toBe('Pedido #45821 — ¿dónde está? 📦')
        ->inReplyTo->toBe('anterior@acme.test')
        ->references->toBe(['primero@acme.test', 'anterior@acme.test'])
        ->seen->toBeFalse()
        ->and($read->from->email)->toBe('cliente@cliente.test')
        ->and($read->from->name)->toBe('Cliente Pérez')
        ->and($read->to[0]->email)->toBe('soporte@acme.test')
        ->and($read->cc[0]->name)->toBe('Jefe')
        ->and($read->replyTo[0]->email)->toBe('respuestas@cliente.test')
        ->and($read->date->toDateTimeString())->toBe('2026-09-30 10:00:00')
        ->and($read->textBody)->toContain('¿dónde está mi pedido #45821?')
        ->and($read->htmlBody)->toContain('<b>#45821</b>')
        ->and($read->attachments)->toBe([]);
})->group('greenmail');

it('lists the latest messages first and respects the limit', function () {
    foreach (['Primero', 'Segundo', 'Tercero'] as $subject) {
        GreenMail::append($this->email, customerEmail($subject));
    }

    $provider = GreenMail::provider($this->email);

    expect(array_map(fn (MailMessage $m) => $m->subject, $provider->messages('INBOX')))
        ->toBe(['Tercero', 'Segundo', 'Primero'])
        ->and(array_map(fn (MailMessage $m) => $m->subject, $provider->messages('INBOX', limit: 2)))
        ->toBe(['Tercero', 'Segundo']);
})->group('greenmail');

it('reads messages from any folder', function () {
    GreenMail::append($this->email, customerEmail('Archivado'), 'Archivo');

    $messages = GreenMail::provider($this->email)->messages('Archivo');

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->folder)->toBe('Archivo');
})->group('greenmail');

it('reports whether a message was already read', function () {
    $uid = GreenMail::append($this->email, customerEmail(), flags: ['\\Seen']);

    expect(GreenMail::provider($this->email)->message('INBOX', (string) $uid)->seen)->toBeTrue();
})->group('greenmail');

it('does not mark messages as read when reading them', function () {
    $uid = GreenMail::append($this->email, customerEmail());
    $provider = GreenMail::provider($this->email);

    $provider->messages('INBOX');
    $provider->message('INBOX', (string) $uid);

    expect(GreenMail::provider($this->email)->message('INBOX', (string) $uid)->seen)->toBeFalse();
})->group('greenmail');

it('returns an empty list for an empty folder', function () {
    expect(GreenMail::provider($this->email)->messages('INBOX'))->toBe([]);
})->group('greenmail');

it('fails with not found for unknown folders or messages', function (string $folder, string $id) {
    GreenMail::append($this->email, customerEmail());

    GreenMail::provider($this->email)->message($folder, $id);
})->group('greenmail')->with([
    'carpeta inexistente' => ['NoExiste', '1'],
    'uid inexistente' => ['INBOX', '999'],
    'id no numérico' => ['INBOX', 'abc'],
])->throws(MailNotFoundException::class);
