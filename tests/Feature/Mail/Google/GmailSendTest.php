<?php

use App\Mail\Credentials\OAuthCredentials;
use App\Mail\Data\MailAddress;
use App\Mail\Data\OutgoingAttachment;
use App\Mail\Data\OutgoingMessage;
use App\Mail\Exceptions\MailAuthenticationException;
use App\Mail\OAuth\StaticTokenSource;
use App\Mail\Providers\Google\GmailClient;
use App\Mail\Providers\Google\GmailProvider;
use Carbon\CarbonImmutable;
use DirectoryTree\ImapEngine\FileMessage;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();

    $token = new StaticTokenSource(new OAuthCredentials('token', 'refresh', CarbonImmutable::now()->addHour(), [GmailProvider::SCOPE_SEND]));
    $this->provider = new GmailProvider(new GmailClient($token), new MailAddress('ventas@empresa.test', 'Ventas Empresa'), [GmailProvider::SCOPE_SEND]);
});

function fakeGmailSend(?string $threadId = null): void
{
    Http::fake(function (Request $request) use ($threadId) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            str_ends_with($path, '/messages/send') => Http::response(['id' => 'sent-1', 'threadId' => $request['threadId'] ?? 'nuevo-hilo']),
            str_ends_with($path, '/messages') => Http::response(['messages' => $threadId ? [['id' => 'orig', 'threadId' => $threadId]] : []]),
            str_ends_with($path, '/messages/sent-1') => Http::response(['id' => 'sent-1', 'payload' => ['headers' => [
                ['name' => 'Message-ID', 'value' => '<CAGmail-reescrito@mail.gmail.com>'],
            ]]]),
            default => Http::response(['error' => ['status' => 'INVALID_ARGUMENT']], 400),
        };
    });
}

/** MIME enviado a Gmail, parseado con el mismo parser que la lectura. */
function sentMime(): FileMessage
{
    $request = Http::recorded()->first(fn ($pair) => str_ends_with(parse_url($pair[0]->url(), PHP_URL_PATH), '/messages/send'))[0];

    return new FileMessage(base64_decode(strtr($request['raw'], '-_', '+/')));
}

it('sends the email as raw mime from the connected account', function () {
    fakeGmailSend();
    $pdf = "%PDF\x00\xff" . random_bytes(32);

    $sent = $this->provider->send(new OutgoingMessage(
        to: [new MailAddress('cliente@cliente.test', 'Cliente Pérez')],
        subject: 'Tu pedido — enviado ✅',
        textBody: 'Sale hoy.',
        htmlBody: '<p>Sale <b>hoy</b>.</p>',
        cc: [new MailAddress('jefe@cliente.test')],
        attachments: [new OutgoingAttachment('albaran.pdf', $pdf, 'application/pdf')],
    ));

    expect($sent->messageId)->toBe('CAGmail-reescrito@mail.gmail.com');

    $mime = sentMime();
    expect($mime->from()->email())->toBe('ventas@empresa.test')
        ->and($mime->from()->name())->toBe('Ventas Empresa')
        ->and($mime->to()[0]->email())->toBe('cliente@cliente.test')
        ->and($mime->cc()[0]->email())->toBe('jefe@cliente.test')
        ->and($mime->subject())->toBe('Tu pedido — enviado ✅')
        ->and($mime->text())->toContain('Sale hoy.')
        ->and($mime->html())->toContain('<b>hoy</b>')
        ->and($mime->attachments()[0]->contents())->toBe($pdf);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'rfc822msgid'));
});

it('answers inside the thread of the original message', function () {
    fakeGmailSend(threadId: 'hilo-123');

    $this->provider->send(new OutgoingMessage(
        to: [new MailAddress('cliente@cliente.test')],
        subject: 'Re: Consulta',
        textBody: 'Respuesta',
        inReplyTo: 'original@cliente.test',
        references: ['original@cliente.test'],
    ));

    Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), 'q=rfc822msgid:original@cliente.test'));
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/messages/send') && $request['threadId'] === 'hilo-123');

    $mime = sentMime();
    expect($mime->inReplyTo())->toBe(['original@cliente.test'])
        ->and($mime->header('References')->getRawValue())->toContain('original@cliente.test');
});

it('sends without thread when the original is not in the mailbox', function () {
    fakeGmailSend(threadId: null);

    $this->provider->send(new OutgoingMessage([new MailAddress('c@c.test')], 'Re: x', 'x', inReplyTo: 'desconocido@x.test'));

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/messages/send') && ! isset($request['threadId']));
});

it('includes bcc recipients so gmail can deliver to them', function () {
    fakeGmailSend();

    $this->provider->send(new OutgoingMessage([new MailAddress('c@c.test')], 'x', 'x', bcc: [new MailAddress('oculto@empresa.test')]));

    // Symfony quita Bcc del MIME; Gmail lo necesita para entregar (y lo elimina al enviar).
    $mime = sentMime();
    expect($mime->bcc()[0]->email())->toBe('oculto@empresa.test')
        ->and($mime->to()[0]->email())->toBe('c@c.test');
});

it('reports a missing send permission as an authentication error', function () {
    Http::fake(['*' => Http::response(['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED']], 403)]);

    $this->provider->send(new OutgoingMessage([new MailAddress('c@c.test')], 'x', 'x'));
})->throws(MailAuthenticationException::class, 'faltan permisos');
