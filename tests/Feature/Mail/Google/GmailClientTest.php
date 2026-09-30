<?php

use App\Mail\Credentials\OAuthCredentials;
use App\Mail\Exceptions\MailAuthenticationException;
use App\Mail\Exceptions\MailConnectionException;
use App\Mail\Exceptions\MailNotFoundException;
use App\Mail\Exceptions\MailProviderException;
use App\Mail\MailProviderFactory;
use App\Mail\OAuth\StaticTokenSource;
use App\Mail\Providers\Google\GmailClient;
use App\Mail\Providers\Google\GmailProvider;
use App\Models\MailConnection;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
 * Gmail API simulada con Http::fake (ninguna petición sale de verdad).
 */

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
});

function gmailClient(string $token = 'token-valido'): GmailClient
{
    return new GmailClient(new StaticTokenSource(
        new OAuthCredentials($token, 'refresh', CarbonImmutable::now()->addHour(), [GmailProvider::SCOPE_READ, GmailProvider::SCOPE_SEND]),
    ));
}

it('calls the gmail api of the connected account with the bearer token', function () {
    Http::fake(['gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response(['emailAddress' => 'ana@empresa.test', 'historyId' => '123'])]);

    expect(gmailClient()->get('/profile'))->toBe(['emailAddress' => 'ana@empresa.test', 'historyId' => '123']);

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer token-valido'));
});

it('translates google errors into mail exceptions', function (int $status, string $googleStatus, string $exception) {
    Http::fake(['*' => Http::response(['error' => ['code' => $status, 'status' => $googleStatus, 'message' => 'x']], $status)]);

    try {
        gmailClient('token-secreto')->get('/messages/abc');
        $this->fail('Debería haber lanzado una excepción.');
    } catch (MailProviderException $e) {
        expect($e)->toBeInstanceOf($exception)
            ->and($e->getMessage())->not->toContain('token-secreto');
    }
})->with([
    '403' => [403, 'PERMISSION_DENIED', MailAuthenticationException::class],
    '404' => [404, 'NOT_FOUND', MailNotFoundException::class],
    '429' => [429, 'RESOURCE_EXHAUSTED', MailConnectionException::class],
    '500' => [500, 'INTERNAL', MailConnectionException::class],
    '400' => [400, 'INVALID_ARGUMENT', MailProviderException::class],
]);

it('includes the google error status in the message', function () {
    Http::fake(['*' => Http::response(['error' => ['status' => 'FAILED_PRECONDITION']], 400)]);

    gmailClient()->get('/messages');
})->throws(MailProviderException::class, 'FAILED_PRECONDITION');

it('tests the connection reading the profile and checking the send permission', function () {
    Http::fake(['gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response(['emailAddress' => 'ana@empresa.test'])]);

    $ok = (new GmailProvider(gmailClient(), [GmailProvider::SCOPE_READ, GmailProvider::SCOPE_SEND]))->testConnection();
    $readOnly = (new GmailProvider(gmailClient(), [GmailProvider::SCOPE_READ]))->testConnection();

    expect($ok->ok())->toBeTrue()
        ->and($readOnly->incomingOk)->toBeTrue()
        ->and($readOnly->outgoingOk)->toBeFalse()
        ->and($readOnly->outgoingError)->toContain('gmail.send');
});

it('reports gmail being unreachable in the connection test without throwing', function () {
    Http::fake(['*' => Http::response(['error' => ['status' => 'UNAVAILABLE']], 503)]);

    $result = (new GmailProvider(gmailClient(), [GmailProvider::SCOPE_SEND]))->testConnection();

    expect($result->incomingOk)->toBeFalse()
        ->and($result->incomingError)->toContain('Gmail no está disponible');
});

it('is the provider built by the factory for google connections', function () {
    $connection = inTenant(Tenant::factory()->create(), fn () => MailConnection::factory()->google()->create());

    expect(app(MailProviderFactory::class)->make($connection))->toBeInstanceOf(GmailProvider::class);
});
