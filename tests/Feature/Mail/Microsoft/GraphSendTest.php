<?php

use App\Mail\Credentials\OAuthCredentials;
use App\Mail\Data\MailAddress;
use App\Mail\Data\OutgoingAttachment;
use App\Mail\Data\OutgoingMessage;
use App\Mail\Exceptions\MailAuthenticationException;
use App\Mail\Exceptions\MailProviderException;
use App\Mail\OAuth\StaticTokenSource;
use App\Mail\Providers\Microsoft\GraphClient;
use App\Mail\Providers\Microsoft\MicrosoftGraphProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();

    $token = new StaticTokenSource(new OAuthCredentials('token', 'refresh', CarbonImmutable::now()->addHour(), ['Mail.Send']));
    $this->provider = new MicrosoftGraphProvider(new GraphClient($token), ['Mail.Send']);
});

/** Graph simulado: guarda los borradores creados y registra las llamadas. */
function fakeGraphMailbox(?string $originalId = null): void
{
    Http::fake(function (Request $request) use ($originalId) {
        $url = urldecode($request->url());

        return match (true) {
            str_contains($url, "/me/messages?") && str_contains($url, 'internetMessageId eq') => Http::response(['value' => $originalId ? [['id' => $originalId]] : []]),
            str_ends_with($url, '/createReply') => Http::response(['id' => 'draft-reply', 'internetMessageId' => '<reply-123@contoso.test>'], 201),
            $request->method() === 'PATCH' => Http::response(['id' => 'draft-reply', 'internetMessageId' => '<reply-123@contoso.test>']),
            str_ends_with($url, '/attachments') => Http::response(['id' => 'att'], 201),
            str_ends_with($url, '/send') => Http::response(null, 202),
            $request->method() === 'POST' && str_ends_with($url, '/me/messages') => Http::response(['id' => 'draft-new', 'internetMessageId' => '<new-456@contoso.test>'], 201),
            default => Http::response(['error' => ['code' => 'Unexpected']], 400),
        };
    });
}

it('sends a new email through a draft and returns its Message-ID', function () {
    fakeGraphMailbox();

    $sent = $this->provider->send(new OutgoingMessage(
        to: [new MailAddress('cliente@cliente.test', 'Cliente Pérez')],
        subject: 'Tu pedido',
        htmlBody: '<p>Sale hoy</p>',
        cc: [new MailAddress('jefe@cliente.test')],
        bcc: [new MailAddress('archivo@contoso.test')],
    ));

    expect($sent->messageId)->toBe('new-456@contoso.test');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/me/messages')
        && $request['subject'] === 'Tu pedido'
        && $request['body'] === ['contentType' => 'HTML', 'content' => '<p>Sale hoy</p>']
        && $request['toRecipients'] === [['emailAddress' => ['address' => 'cliente@cliente.test', 'name' => 'Cliente Pérez']]]
        && $request['ccRecipients'][0]['emailAddress']['address'] === 'jefe@cliente.test'
        && $request['bccRecipients'][0]['emailAddress']['address'] === 'archivo@contoso.test'
        && ! isset($request['from']));
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/me/messages/draft-new/send'));
});

it('sends plain text bodies as text', function () {
    fakeGraphMailbox();

    $this->provider->send(new OutgoingMessage([new MailAddress('c@c.test')], 'Hola', textBody: "Línea 1\nLínea 2"));

    Http::assertSent(fn (Request $request) => ($request['body'] ?? null) === ['contentType' => 'Text', 'content' => "Línea 1\nLínea 2"]);
});

it('answers in the same conversation using createReply', function () {
    fakeGraphMailbox(originalId: 'AAOriginal');

    $sent = $this->provider->send(new OutgoingMessage(
        to: [new MailAddress('cliente@cliente.test')],
        subject: 'Re: Consulta',
        textBody: 'Respuesta',
        inReplyTo: 'original@cliente.test',
    ));

    expect($sent->messageId)->toBe('reply-123@contoso.test');

    Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), "internetMessageId eq '<original@cliente.test>'"));
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/me/messages/AAOriginal/createReply'));
    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && $request['body']['content'] === 'Respuesta');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/me/messages/draft-reply/send'));
});

it('falls back to a new email when the original is not in the mailbox', function () {
    fakeGraphMailbox(originalId: null);

    $sent = $this->provider->send(new OutgoingMessage([new MailAddress('c@c.test')], 'Re: x', 'x', inReplyTo: 'desconocido@x.test'));

    expect($sent->messageId)->toBe('new-456@contoso.test');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'createReply'));
});

it('uploads attachments to the draft before sending', function () {
    fakeGraphMailbox();
    $pdf = "%PDF\x00\xff" . random_bytes(32);

    $this->provider->send(new OutgoingMessage(
        to: [new MailAddress('c@c.test')],
        subject: 'Factura',
        textBody: 'Adjunta',
        attachments: [new OutgoingAttachment('factura.pdf', $pdf, 'application/pdf')],
    ));

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/me/messages/draft-new/attachments')
        && $request['@odata.type'] === '#microsoft.graph.fileAttachment'
        && $request['name'] === 'factura.pdf'
        && base64_decode($request['contentBytes']) === $pdf);

    // Orden: borrador → adjunto → envío.
    $urls = Http::recorded()->map(fn ($pair) => $pair[0]->url())->values()->all();
    expect(array_search(collect($urls)->first(fn ($u) => str_ends_with($u, '/attachments')), $urls))
        ->toBeLessThan(array_search(collect($urls)->first(fn ($u) => str_ends_with($u, '/send')), $urls));
});

it('rejects attachments over 3 MB before creating anything', function () {
    Http::fake();

    expect(fn () => $this->provider->send(new OutgoingMessage(
        to: [new MailAddress('c@c.test')],
        subject: 'Grande',
        textBody: 'x',
        attachments: [new OutgoingAttachment('video.mp4', str_repeat('x', 3 * 1024 * 1024 + 1))],
    )))->toThrow(MailProviderException::class, 'supera los 3 MB');

    Http::assertNothingSent();
});

it('reports a missing send permission as an authentication error', function () {
    Http::fake(['*' => Http::response(['error' => ['code' => 'ErrorAccessDenied']], 403)]);

    $this->provider->send(new OutgoingMessage([new MailAddress('c@c.test')], 'x', 'x'));
})->throws(MailAuthenticationException::class, 'faltan permisos');
