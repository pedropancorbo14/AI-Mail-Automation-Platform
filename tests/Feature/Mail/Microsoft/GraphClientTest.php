<?php

use App\Mail\Credentials\OAuthCredentials;
use App\Mail\Exceptions\MailAuthenticationException;
use App\Mail\Exceptions\MailConnectionException;
use App\Mail\Exceptions\MailNotFoundException;
use App\Mail\Exceptions\MailProviderException;
use App\Mail\MailProviderFactory;
use App\Mail\OAuth\Contracts\TokenSource;
use App\Mail\OAuth\StaticTokenSource;
use App\Mail\Providers\Microsoft\GraphClient;
use App\Mail\Providers\Microsoft\MicrosoftGraphProvider;
use App\Models\MailConnection;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
 * API de Microsoft simulada con Http::fake: ninguna petición sale de verdad
 * (preventStrayRequests hace fallar el test si alguna se escapa).
 */

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake(); // los reintentos no esperan de verdad
});

function graphClient(string $token = 'token-valido', ?TokenSource $source = null): GraphClient
{
    return new GraphClient($source ?? new StaticTokenSource(
        new OAuthCredentials($token, 'refresh', CarbonImmutable::now()->addHour(), ['Mail.ReadWrite', 'Mail.Send']),
    ));
}

it('sends the bearer token and parses json', function () {
    Http::fake(['graph.microsoft.com/v1.0/me*' => Http::response(['mail' => 'ana@contoso.test'])]);

    expect(graphClient()->get('/me', ['$select' => 'mail']))->toBe(['mail' => 'ana@contoso.test']);

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer token-valido')
        && str_contains(urldecode($request->url()), '$select=mail'));
});

it('translates http errors into mail exceptions without leaking the token', function (int $status, string $exception) {
    Http::fake(['*' => Http::response(['error' => ['code' => 'SomeGraphError', 'message' => 'x']], $status)]);

    try {
        graphClient('token-secreto')->get('/me/messages/abc');
        $this->fail('Debería haber lanzado una excepción.');
    } catch (MailProviderException $e) {
        // 401: no se puede renovar un token estático → mensaje de reconectar.
        expect($e)->toBeInstanceOf($exception)
            ->and($e->getMessage())->toContain($status === 401 ? 'volver a conectar' : 'Microsoft')
            ->not->toContain('token-secreto');
    }
})->with([
    '401' => [401, MailAuthenticationException::class],
    '403' => [403, MailAuthenticationException::class],
    '404' => [404, MailNotFoundException::class],
    '429' => [429, MailConnectionException::class],
    '503' => [503, MailConnectionException::class],
    '400' => [400, MailProviderException::class],
]);

it('retries throttled or unavailable responses', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['error' => ['code' => 'TooManyRequests']], 429)
        ->push(['error' => ['code' => 'ServiceUnavailable']], 503)
        ->push(['ok' => true])]);

    expect(graphClient()->get('/me'))->toBe(['ok' => true]);
    Http::assertSentCount(3);
});

it('refreshes the token once when the api rejects it', function () {
    $source = new class implements TokenSource
    {
        public int $refreshes = 0;

        public function accessToken(): string
        {
            return 'token-revocado';
        }

        public function refresh(): string
        {
            $this->refreshes++;

            return 'token-nuevo';
        }
    };

    Http::fake(function (Request $request) {
        return $request->hasHeader('Authorization', 'Bearer token-nuevo')
            ? Http::response(['ok' => true])
            : Http::response(['error' => ['code' => 'InvalidAuthenticationToken']], 401);
    });

    expect(graphClient(source: $source)->get('/me'))->toBe(['ok' => true])
        ->and($source->refreshes)->toBe(1);
});

it('follows @odata.nextLink up to the requested limit', function () {
    Http::fake(fn (Request $request) => str_contains(urldecode($request->url()), 'skiptoken=page2')
        ? Http::response([
            'value' => [['id' => '3'], ['id' => '4']],
            '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/messages?$skiptoken=page3',
        ])
        : Http::response([
            'value' => [['id' => '1'], ['id' => '2']],
            '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/messages?$skiptoken=page2',
        ]));

    $items = graphClient()->collect('/me/messages', ['$top' => 2], limit: 3);

    expect(array_column($items, 'id'))->toBe(['1', '2', '3']);
    Http::assertSentCount(2);
});

it('refuses to use an expired token without being able to renew it', function () {
    $expired = new StaticTokenSource(new OAuthCredentials('viejo', 'refresh', CarbonImmutable::now()->subMinute()));

    (new GraphClient($expired))->get('/me');
})->throws(MailAuthenticationException::class, 'volver a conectar');

// --- Proveedor ---------------------------------------------------------------

it('tests the connection reading the inbox and checking the send permission', function () {
    Http::fake(['graph.microsoft.com/v1.0/me/mailFolders/inbox*' => Http::response(['id' => 'inbox-id'])]);

    $ok = (new MicrosoftGraphProvider(graphClient(), ['Mail.ReadWrite', 'https://graph.microsoft.com/Mail.Send']))->testConnection();
    $noSend = (new MicrosoftGraphProvider(graphClient(), ['Mail.ReadWrite']))->testConnection();

    expect($ok->ok())->toBeTrue()
        ->and($noSend->incomingOk)->toBeTrue()
        ->and($noSend->outgoingOk)->toBeFalse()
        ->and($noSend->outgoingError)->toContain('Mail.Send');
});

it('reports a rejected token in the connection test without throwing', function () {
    Http::fake(['*' => Http::response(['error' => ['code' => 'InvalidAuthenticationToken']], 401)]);

    $result = (new MicrosoftGraphProvider(graphClient(), ['Mail.Send']))->testConnection();

    expect($result->incomingOk)->toBeFalse()
        ->and($result->incomingError)->toContain('volver a conectarla');
});

it('is the provider built by the factory for microsoft connections', function () {
    $connection = inTenant(Tenant::factory()->create(), fn () => MailConnection::factory()->microsoft()->create());

    expect(app(MailProviderFactory::class)->make($connection))->toBeInstanceOf(MicrosoftGraphProvider::class);
});
