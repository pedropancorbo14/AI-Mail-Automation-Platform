<?php

use App\Mail\Credentials\OAuthCredentials;
use App\Mail\Data\MailFolder;
use App\Mail\Exceptions\MailNotFoundException;
use App\Mail\Exceptions\MailProviderException;
use App\Mail\OAuth\StaticTokenSource;
use App\Mail\Providers\Microsoft\GraphClient;
use App\Mail\Providers\Microsoft\MicrosoftGraphProvider;
use App\Mail\Support\HtmlToText;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
 * Lectura de Microsoft Graph con respuestas simuladas con el formato real
 * de la API (https://learn.microsoft.com/graph/api/resources/message).
 */

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
});

function graphProvider(int $maxAttachmentBytes = 25 * 1024 * 1024, int $batch = 100): MicrosoftGraphProvider
{
    $token = new StaticTokenSource(new OAuthCredentials('token', 'refresh', CarbonImmutable::now()->addHour(), ['Mail.Send']));

    return new MicrosoftGraphProvider(new GraphClient($token), ['Mail.Send'], $maxAttachmentBytes, $batch);
}

/** Mensaje con el formato JSON de Graph. */
function graphMessage(string $id, array $overrides = []): array
{
    return array_replace_recursive([
        'id' => $id,
        'internetMessageId' => "<{$id}@contoso.test>",
        'subject' => "Asunto {$id}",
        'from' => ['emailAddress' => ['name' => 'Cliente Pérez', 'address' => 'cliente@cliente.test']],
        'toRecipients' => [['emailAddress' => ['name' => 'Soporte', 'address' => 'soporte@contoso.test']]],
        'ccRecipients' => [],
        'replyTo' => [],
        'receivedDateTime' => '2026-09-30T10:00:00Z',
        'body' => ['contentType' => 'html', 'content' => '<p>Hola,<br>¿dónde está mi pedido <b>#45821</b>?</p>'],
        'isRead' => false,
        'internetMessageHeaders' => [],
        'attachments' => [],
    ], $overrides);
}

it('lists folders with their roles and direct subfolders', function () {
    Http::fake([
        'graph.microsoft.com/v1.0/$batch' => Http::response(['responses' => [
            ['id' => 'inbox', 'status' => 200, 'body' => ['id' => 'AAInbox']],
            ['id' => 'sentitems', 'status' => 200, 'body' => ['id' => 'AASent']],
            ['id' => 'archive', 'status' => 404, 'body' => ['error' => ['code' => 'ErrorFolderNotFound']]],
        ]]),
        'graph.microsoft.com/v1.0/me/mailFolders/AAInbox/childFolders*' => Http::response(['value' => [
            ['id' => 'AAClientes', 'displayName' => 'Clientes', 'childFolderCount' => 0],
        ]]),
        'graph.microsoft.com/v1.0/me/mailFolders?*' => Http::response(['value' => [
            ['id' => 'AAInbox', 'displayName' => 'Bandeja de entrada', 'childFolderCount' => 1],
            ['id' => 'AASent', 'displayName' => 'Elementos enviados', 'childFolderCount' => 0],
            ['id' => 'AAVentas', 'displayName' => 'Ventas', 'childFolderCount' => 0],
        ]]),
    ]);

    $folders = collect(graphProvider()->folders())->keyBy('id');

    expect($folders->keys()->all())->toBe(['AAInbox', 'AAClientes', 'AASent', 'AAVentas'])
        ->and($folders['AAInbox']->role)->toBe(MailFolder::INBOX)
        ->and($folders['AASent']->role)->toBe(MailFolder::SENT)
        ->and($folders['AAVentas']->role)->toBeNull()
        ->and($folders['AAClientes']->name)->toBe('Bandeja de entrada/Clientes');

    // Las 6 carpetas especiales en una sola petición $batch.
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '$batch') && count($request['requests']) === 6);
});

it('reads messages mapped to our format (latest first)', function () {
    Http::fake(['graph.microsoft.com/v1.0/me/mailFolders/inbox/messages*' => Http::response(['value' => [
        graphMessage('m2', [
            'subject' => 'Pedido #45821 — ¿dónde está? 📦',
            'ccRecipients' => [['emailAddress' => ['address' => 'jefe@cliente.test']]],
            'replyTo' => [['emailAddress' => ['address' => 'respuestas@cliente.test']]],
            'isRead' => true,
            'internetMessageHeaders' => [
                ['name' => 'In-Reply-To', 'value' => '<anterior@contoso.test>'],
                ['name' => 'References', 'value' => '<primero@contoso.test> <anterior@contoso.test>'],
            ],
            'attachments' => [['id' => 'att1', 'name' => 'factura.pdf', 'contentType' => 'application/pdf', 'size' => 2048, 'isInline' => false]],
        ]),
        graphMessage('m1'),
    ]])]);

    [$latest, $older] = graphProvider()->messages('inbox', limit: 2);

    expect($latest)
        ->id->toBe('m2')
        ->folder->toBe('inbox')
        ->messageId->toBe('m2@contoso.test')
        ->subject->toBe('Pedido #45821 — ¿dónde está? 📦')
        ->inReplyTo->toBe('anterior@contoso.test')
        ->references->toBe(['primero@contoso.test', 'anterior@contoso.test'])
        ->seen->toBeTrue()
        ->and($latest->from->name)->toBe('Cliente Pérez')
        ->and($latest->cc[0]->email)->toBe('jefe@cliente.test')
        ->and($latest->replyTo[0]->email)->toBe('respuestas@cliente.test')
        ->and($latest->date->toIso8601ZuluString())->toBe('2026-09-30T10:00:00Z')
        ->and($latest->htmlBody)->toContain('<b>#45821</b>')
        ->and($latest->textBody)->toBe("Hola,\n¿dónde está mi pedido #45821?")
        ->and($latest->attachments[0]->filename)->toBe('factura.pdf')
        ->and($latest->attachments[0]->size)->toBe(2048)
        ->and($older->id)->toBe('m1');

    Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), '$orderby=receivedDateTime desc')
        && str_contains(urldecode($request->url()), 'internetMessageHeaders'));
});

it('reads a single message', function () {
    Http::fake(['graph.microsoft.com/v1.0/me/messages/AAMk%3D%3D*' => Http::response(graphMessage('AAMk=='))]);

    expect(graphProvider()->message('inbox', 'AAMk==')->id)->toBe('AAMk==');
});

it('reports unknown messages as not found', function () {
    Http::fake(['*' => Http::response(['error' => ['code' => 'ErrorItemNotFound']], 404)]);

    graphProvider()->message('inbox', 'no-existe');
})->throws(MailNotFoundException::class);

it('downloads file attachments after checking their size', function () {
    $pdf = "%PDF\x00\xff" . random_bytes(64);
    Http::fake(['graph.microsoft.com/v1.0/me/messages/m1/attachments/att1*' => Http::response([
        '@odata.type' => '#microsoft.graph.fileAttachment',
        'id' => 'att1', 'name' => 'factura.pdf', 'contentType' => 'application/pdf',
        'size' => strlen($pdf), 'isInline' => false, 'contentBytes' => base64_encode($pdf),
    ])]);

    $download = graphProvider()->downloadAttachment('inbox', 'm1', 'att1');

    expect($download->content)->toBe($pdf)
        ->and($download->attachment->filename)->toBe('factura.pdf');
});

it('refuses attachments above the size limit without downloading them', function () {
    Http::fake(['*' => Http::response([
        '@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'att1', 'name' => 'enorme.zip', 'size' => 5000,
    ])]);

    expect(fn () => graphProvider(maxAttachmentBytes: 1024)->downloadAttachment('inbox', 'm1', 'att1'))
        ->toThrow(MailProviderException::class, 'supera el tamaño máximo');

    Http::assertSentCount(1); // solo metadatos
});

it('explains that item and reference attachments cannot be downloaded', function () {
    Http::fake(['*' => Http::response(['@odata.type' => '#microsoft.graph.itemAttachment', 'id' => 'a', 'name' => 'Correo reenviado'])]);

    graphProvider()->downloadAttachment('inbox', 'm1', 'a');
})->throws(MailProviderException::class, 'no es un fichero');

// --- Nuevos mensajes ---------------------------------------------------------------

it('only sets the starting point on the first sync', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-30 10:00:00'));

    $result = graphProvider()->newMessages('inbox', null);

    expect($result->messages)->toBe([])->and($result->resynced)->toBeFalse();
    Http::assertNothingSent();
});

it('returns messages received since the cursor without repeating any', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-30 10:00:00'));
    $provider = graphProvider();
    $cursor = $provider->newMessages('inbox', null)->cursor;

    Http::fake(['*' => Http::sequence()
        ->push(['value' => [
            graphMessage('a', ['receivedDateTime' => '2026-09-30T10:00:05Z']),
            graphMessage('b', ['receivedDateTime' => '2026-09-30T10:00:07Z']),
        ]])
        // Siguiente consulta ("ge 10:00:07"): Graph devuelve otra vez "b" y uno nuevo del mismo segundo.
        ->push(['value' => [
            graphMessage('b', ['receivedDateTime' => '2026-09-30T10:00:07Z']),
            graphMessage('c', ['receivedDateTime' => '2026-09-30T10:00:07Z']),
        ]])
        ->push(['value' => [
            graphMessage('b', ['receivedDateTime' => '2026-09-30T10:00:07Z']),
            graphMessage('c', ['receivedDateTime' => '2026-09-30T10:00:07Z']),
        ]])]);

    $first = $provider->newMessages('inbox', $cursor);
    $second = $provider->newMessages('inbox', $first->cursor);
    $third = $provider->newMessages('inbox', $second->cursor);

    expect(array_map(fn ($m) => $m->id, $first->messages))->toBe(['a', 'b'])
        ->and(array_map(fn ($m) => $m->id, $second->messages))->toBe(['c'])
        ->and($third->messages)->toBe([])
        ->and($third->cursor)->toBe($second->cursor);

    Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), 'receivedDateTime ge 2026-09-30T10:00:00Z')
        && str_contains(urldecode($request->url()), '$orderby=receivedDateTime asc'));
});

it('delivers backlogs in batches', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-30 10:00:00'));
    $provider = graphProvider(batch: 2);
    $cursor = $provider->newMessages('inbox', null)->cursor;

    Http::fake(['*' => Http::response(['value' => [
        graphMessage('a', ['receivedDateTime' => '2026-09-30T10:00:01Z']),
        graphMessage('b', ['receivedDateTime' => '2026-09-30T10:00:02Z']),
        graphMessage('c', ['receivedDateTime' => '2026-09-30T10:00:03Z']),
    ]])]);

    $result = $provider->newMessages('inbox', $cursor);

    expect(array_map(fn ($m) => $m->id, $result->messages))->toBe(['a', 'b'])
        ->and($result->hasMore)->toBeTrue();
});

it('resyncs with an unreadable cursor', function () {
    $result = graphProvider()->newMessages('inbox', 'basura');

    expect($result->resynced)->toBeTrue()->and($result->messages)->toBe([]);
});

it('converts html bodies into readable text', function () {
    $html = '<html><head><style>p{color:red}</style></head><body><p>Hola&nbsp;Ana,</p><p>Tu pedido:<br>- Silla&amp;Mesa</p><script>x()</script></body></html>';

    expect(HtmlToText::convert($html))->toBe("Hola Ana,\nTu pedido:\n- Silla&Mesa");
});
