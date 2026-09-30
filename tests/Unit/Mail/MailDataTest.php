<?php

use App\Mail\Data\ConnectionTestResult;
use App\Mail\Data\MailAddress;
use App\Mail\Data\MailMessage;
use App\Mail\Data\OutgoingMessage;
use App\Mail\Exceptions\MailNotFoundException;
use Tests\Fakes\FakeMailProvider;

it('formats addresses with and without name', function () {
    expect((new MailAddress('ana@acme.test'))->toString())->toBe('ana@acme.test')
        ->and((new MailAddress('ana@acme.test', 'Ana "Jefa" López'))->toString())
        ->toBe('"Ana \"Jefa\" López" <ana@acme.test>');
});

it('requires at least one recipient to send', function () {
    new OutgoingMessage(to: [], subject: 'Hola', textBody: 'x');
})->throws(InvalidArgumentException::class, 'destinatario');

it('requires a body to send', function () {
    new OutgoingMessage(to: [new MailAddress('a@b.test')], subject: 'Hola');
})->throws(InvalidArgumentException::class, 'cuerpo');

it('accepts bcc only recipients', function () {
    $message = new OutgoingMessage(to: [], subject: 'Hola', htmlBody: '<p>x</p>', bcc: [new MailAddress('a@b.test')]);

    expect($message->bcc)->toHaveCount(1);
});

it('reports which side of a connection failed', function () {
    $result = new ConnectionTestResult(incomingOk: true, outgoingOk: false, outgoingError: 'SMTP: autenticación fallida');

    expect($result->ok())->toBeFalse()
        ->and($result->error())->toBe('SMTP: autenticación fallida')
        ->and((new ConnectionTestResult(true, true))->ok())->toBeTrue();
});

function fakeMessage(string $id): MailMessage
{
    return new MailMessage($id, 'INBOX', "{$id}@acme.test", "Asunto {$id}", null, [], [], [], null, 'cuerpo', null);
}

it('can be implemented by a provider (fake)', function () {
    $provider = new FakeMailProvider(['INBOX' => [fakeMessage('1'), fakeMessage('2')], 'Sent' => []]);

    expect(array_map(fn ($f) => $f->id, $provider->folders()))->toBe(['INBOX', 'Sent'])
        ->and(array_map(fn ($m) => $m->id, $provider->messages('INBOX')))->toBe(['2', '1'])
        ->and($provider->message('INBOX', '2')->subject)->toBe('Asunto 2');

    $first = $provider->newMessages('INBOX', null);
    expect($first->messages)->toBe([]);

    $provider->mailboxes['INBOX'][] = fakeMessage('3');
    $next = $provider->newMessages('INBOX', $first->cursor);
    expect(array_map(fn ($m) => $m->id, $next->messages))->toBe(['3']);

    $provider->message('INBOX', 'no-existe');
})->throws(MailNotFoundException::class);
