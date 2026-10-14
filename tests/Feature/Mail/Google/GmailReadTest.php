<?php

use App\Mail\Credentials\OAuthCredentials;
use App\Mail\Data\MailAddress;
use App\Mail\Data\MailFolder;
use App\Mail\Exceptions\MailNotFoundException;
use App\Mail\Exceptions\MailProviderException;
use App\Mail\OAuth\StaticTokenSource;
use App\Mail\Providers\Google\GmailClient;
use App\Mail\Providers\Google\GmailProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/*
 * Gmail API simulada devolviendo correos MIME reales en format=raw
 * (base64url), como la API de verdad.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
});

function gmailProvider(int $maxAttachmentBytes = 25 * 1024 * 1024, int $batch = 100): GmailProvider
{
    $token = new StaticTokenSource(new OAuthCredentials('token', 'refresh', CarbonImmutable::now()->addHour(), [GmailProvider::SCOPE_SEND]));

    return new GmailProvider(new GmailClient($token), new MailAddress('ventas@empresa.test'), [GmailProvider::SCOPE_SEND], $maxAttachmentBytes, $batch);
}

function gmailRaw(string $subject, array $labels = ['INBOX', 'UNREAD'], ?Closure $customize = null): array
{
    $email = (new Email())
        ->from(new Address('cliente@cliente.test', 'Cliente Pérez'))
        ->to('ventas@empresa.test')
        ->subject($subject)
        ->text('¿Tenéis stock?')
        ->date(new DateTimeImmutable('2026-09-30 10:00:00'));
    $email->getHeaders()->addIdHeader('Message-ID', md5($subject) . '@cliente.test');

    if ($customize) {
        $customize($email);
    }

    return ['id' => substr(md5($subject), 0, 16), 'labelIds' => $labels, 'raw' => rtrim(strtr(base64_encode($email->toString()), '+/', '-_'), '=')];
}

/** Buzón simulado: lista, mensajes en crudo, perfil e historial. */
function fakeGmailMailbox(array $messages, array $history = [], string $historyId = '2000', bool $historyExpired = false): void
{
    $byId = collect($messages)->keyBy('id');

    Http::fake(function (Request $request) use ($byId, $history, $historyId, $historyExpired) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            str_ends_with($path, '/profile') => Http::response(['emailAddress' => 'ventas@empresa.test', 'historyId' => '1000']),
            str_ends_with($path, '/labels') => Http::response(['labels' => [
                ['id' => 'INBOX', 'name' => 'INBOX', 'type' => 'system'],
                ['id' => 'SENT', 'name' => 'SENT', 'type' => 'system'],
                ['id' => 'UNREAD', 'name' => 'UNREAD', 'type' => 'system'],
                ['id' => 'CATEGORY_PROMOTIONS', 'name' => 'CATEGORY_PROMOTIONS', 'type' => 'system'],
                ['id' => 'Label_7', 'name' => 'Clientes/VIP', 'type' => 'user'],
            ]]),
            // Como la API real: solo registros POSTERIORES a startHistoryId.
            str_ends_with($path, '/history') => $historyExpired
                ? Http::response(['error' => ['code' => 404, 'status' => 'NOT_FOUND']], 404)
                : Http::response([
                    'history' => array_values(array_filter($history, fn ($h) => (int) $h['id'] > (int) $request['startHistoryId'])),
                    'historyId' => $historyId,
                ]),
            str_ends_with($path, '/messages') => Http::response(['messages' => $byId->map(fn ($m) => ['id' => $m['id']])->values()->all()]),
            default => ($message = $byId->get(basename($path)))
                ? Http::response($message)
                : Http::response(['error' => ['code' => 404, 'status' => 'NOT_FOUND']], 404),
        };
    });
}

it('lists labels that act as folders with their roles', function () {
    fakeGmailMailbox([]);

    $folders = collect(gmailProvider()->folders())->keyBy('id');

    expect($folders->keys()->all())->toBe(['INBOX', 'SENT', 'Label_7'])
        ->and($folders['INBOX']->role)->toBe(MailFolder::INBOX)
        ->and($folders['SENT']->role)->toBe(MailFolder::SENT)
        ->and($folders['Label_7']->name)->toBe('Clientes/VIP')
        ->and($folders['Label_7']->role)->toBeNull();
});

it('reads messages from the raw mime (same parser as imap)', function () {
    $raw = gmailRaw('Pedido #45821 — ¿stock? 📦', ['INBOX'], function (Email $email) {
        $email->cc('jefe@cliente.test')->html('<p>¿Tenéis <b>stock</b>?</p>')->attach('%PDF', 'pedido.pdf', 'application/pdf');
        $email->getHeaders()->addIdHeader('In-Reply-To', 'anterior@empresa.test');
    });
    fakeGmailMailbox([$raw]);

    [$message] = gmailProvider()->messages('INBOX');

    expect($message)
        ->id->toBe($raw['id'])
        ->folder->toBe('INBOX')
        ->subject->toBe('Pedido #45821 — ¿stock? 📦')
        ->inReplyTo->toBe('anterior@empresa.test')
        ->seen->toBeTrue() // sin etiqueta UNREAD
        ->and($message->messageId)->toEndWith('@cliente.test')
        ->and($message->from->name)->toBe('Cliente Pérez')
        ->and($message->cc[0]->email)->toBe('jefe@cliente.test')
        ->and($message->textBody)->toContain('¿Tenéis stock?')
        ->and($message->htmlBody)->toContain('<b>stock</b>')
        ->and($message->attachments[0]->filename)->toBe('pedido.pdf');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'format=raw'));
});

it('marks messages with the UNREAD label as not seen', function () {
    fakeGmailMailbox([$raw = gmailRaw('Sin leer', ['INBOX', 'UNREAD'])]);

    expect(gmailProvider()->message('INBOX', $raw['id'])->seen)->toBeFalse();
});

it('downloads attachments byte for byte', function () {
    $pdf = "%PDF\x00\xff" . random_bytes(128);
    fakeGmailMailbox([$raw = gmailRaw('Factura', customize: fn (Email $e) => $e->attach($pdf, 'factura.pdf', 'application/pdf'))]);

    $download = gmailProvider()->downloadAttachment('INBOX', $raw['id'], '0');

    expect($download->content)->toBe($pdf)->and($download->attachment->filename)->toBe('factura.pdf');
});

it('refuses attachments above the size limit', function () {
    fakeGmailMailbox([$raw = gmailRaw('Grande', customize: fn (Email $e) => $e->attach(str_repeat('x', 4096), 'grande.bin'))]);

    gmailProvider(maxAttachmentBytes: 1024)->downloadAttachment('INBOX', $raw['id'], '0');
})->throws(MailProviderException::class, 'supera el tamaño máximo');

it('reports unknown messages as not found', function () {
    fakeGmailMailbox([]);

    gmailProvider()->message('INBOX', 'noexiste');
})->throws(MailNotFoundException::class);

// --- Nuevos mensajes (History API) ------------------------------------------------

it('starts from the current historyId without history', function () {
    fakeGmailMailbox([gmailRaw('Antiguo')]);

    $result = gmailProvider()->newMessages('INBOX', null);

    expect($result->messages)->toBe([]);
    Http::assertSent(fn (Request $request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/profile'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/history'));
});

it('returns the messages added since the cursor', function () {
    $a = gmailRaw('Nuevo A');
    $b = gmailRaw('Nuevo B');
    fakeGmailMailbox([$a, $b], history: [
        ['id' => '1001', 'messagesAdded' => [['message' => ['id' => $a['id']]]]],
        ['id' => '1002', 'messagesAdded' => [['message' => ['id' => $b['id']]], ['message' => ['id' => $a['id']]]]],
    ], historyId: '1002');

    $provider = gmailProvider();
    $cursor = $provider->newMessages('INBOX', null)->cursor;
    $result = $provider->newMessages('INBOX', $cursor);

    expect(array_map(fn ($m) => $m->subject, $result->messages))->toBe(['Nuevo A', 'Nuevo B'])
        ->and($result->hasMore)->toBeFalse();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/history')
        && $request['startHistoryId'] === '1000'
        && $request['labelId'] === 'INBOX'
        && $request['historyTypes'] === 'messageAdded');
});

it('keeps the messages that did not fit in the batch for the next call', function () {
    $messages = [gmailRaw('M1'), gmailRaw('M2'), gmailRaw('M3')];
    fakeGmailMailbox($messages, history: [
        ['id' => '1001', 'messagesAdded' => array_map(fn ($m) => ['message' => ['id' => $m['id']]], $messages)],
    ], historyId: '1001');

    $provider = gmailProvider(batch: 2);
    $cursor = $provider->newMessages('INBOX', null)->cursor;

    $first = $provider->newMessages('INBOX', $cursor);
    $second = $provider->newMessages('INBOX', $first->cursor);

    expect(array_map(fn ($m) => $m->subject, $first->messages))->toBe(['M1', 'M2'])
        ->and($first->hasMore)->toBeTrue()
        ->and(array_map(fn ($m) => $m->subject, $second->messages))->toBe(['M3']);
});

it('resyncs when the historyId is too old', function () {
    fakeGmailMailbox([], historyExpired: true);
    $cursor = 'v1:' . base64_encode(json_encode(['history' => '5', 'pending' => []]));

    $result = gmailProvider()->newMessages('INBOX', $cursor);

    expect($result->resynced)->toBeTrue()->and($result->messages)->toBe([]);
});

it('resyncs with an unreadable cursor', function () {
    fakeGmailMailbox([]);

    expect(gmailProvider()->newMessages('INBOX', 'basura')->resynced)->toBeTrue();
});

it('detects automated emails by their headers', function () {
    fakeGmailMailbox([$raw = gmailRaw('Ofertas de la semana', ['INBOX'], function (Email $email) {
        $email->getHeaders()->addTextHeader('Precedence', 'bulk');
    })]);

    expect(gmailProvider()->message('INBOX', $raw['id'])->automatedReason)->toContain('Precedence: bulk');
});
