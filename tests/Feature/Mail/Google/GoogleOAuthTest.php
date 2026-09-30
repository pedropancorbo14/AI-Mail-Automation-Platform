<?php

use App\Mail\Credentials\OAuthCredentials;
use App\Mail\Enums\MailConnectionStatus;
use App\Mail\Enums\MailProviderType;
use App\Mail\Exceptions\MailAuthenticationException;
use App\Mail\MailProviderFactory;
use App\Mail\OAuth\MailOAuthService;
use App\Mail\OAuth\OAuthClients;
use App\Mail\OAuth\OAuthTokenManager;
use App\Mail\Providers\Google\GmailProvider;
use App\Models\MailConnection;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
 * Google (accounts.google.com, oauth2.googleapis.com y Gmail API) simulado.
 * El flujo común (state, PKCE, reconexión, lock) ya está cubierto en los
 * tests de Microsoft; aquí lo específico de Google.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();

    config([
        'services.google.client_id' => 'google-client-id',
        'services.google.client_secret' => 'google-client-secret',
        'services.google.redirect_uri' => 'https://app.test/api/v1/mail-connections/oauth/google/callback',
    ]);
    app()->forgetInstance(OAuthClients::class);

    $this->tenant = Tenant::factory()->create();
    $this->admin = User::factory()->for($this->tenant)->admin()->create();
});

function fakeGoogle(array $token = []): void
{
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(array_merge([
            'access_token' => 'ya29.access-nuevo',
            'refresh_token' => '1//refresh-nuevo',
            'expires_in' => 3599,
            'scope' => GmailProvider::SCOPE_READ . ' ' . GmailProvider::SCOPE_SEND,
            'token_type' => 'Bearer',
        ], $token)),
        'gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response(['emailAddress' => 'Ventas@Empresa.test', 'historyId' => '1000']),
    ]);
}

function startGoogle(User $user): array
{
    $url = test()->actingAs($user, 'sanctum')
        ->postJson('/api/v1/mail-connections/oauth/google/start')
        ->assertOk()
        ->json('authorization_url');

    parse_str(parse_url($url, PHP_URL_QUERY), $query);

    return [$query, $url];
}

it('builds the google authorization url asking for offline access', function () {
    [$query, $url] = startGoogle($this->admin);

    expect($url)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?')
        ->and($query['client_id'])->toBe('google-client-id')
        ->and($query['access_type'])->toBe('offline')
        ->and($query['prompt'])->toContain('consent')
        ->and(explode(' ', $query['scope']))->toBe([GmailProvider::SCOPE_READ, GmailProvider::SCOPE_SEND])
        ->and($query['code_challenge_method'])->toBe('S256');
});

it('creates a google connection with the gmail address', function () {
    fakeGoogle();
    [$query] = startGoogle($this->admin);
    app('auth')->forgetGuards();

    $response = $this->getJson('/api/v1/mail-connections/oauth/google/callback?' . http_build_query(['code' => '4/codigo', 'state' => $query['state']]))
        ->assertCreated()
        ->assertJsonPath('data.provider', 'google')
        ->assertJsonPath('data.email', 'ventas@empresa.test')
        ->assertJsonPath('data.name', 'ventas@empresa.test');

    $connection = inTenant($this->tenant, fn () => MailConnection::findOrFail($response->json('data.id')));
    expect($connection->credentials->refreshToken)->toBe('1//refresh-nuevo')
        ->and($connection->credentials->scopes)->toContain(GmailProvider::SCOPE_SEND);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'oauth2.googleapis.com/token')
        && $request['grant_type'] === 'authorization_code'
        && $request['client_secret'] === 'google-client-secret'
        && MailOAuthService::codeChallenge($request['code_verifier']) === $query['code_challenge']);
});

it('keeps the refresh token when google renews without returning a new one', function () {
    fakeGoogle(['refresh_token' => null, 'scope' => null]);
    $connection = inTenant($this->tenant, fn () => MailConnection::factory()->google()->create([
        'credentials' => new OAuthCredentials('ya29.viejo', '1//refresh-original', CarbonImmutable::now()->subMinute(), [GmailProvider::SCOPE_SEND]),
    ]));

    $token = (new OAuthTokenManager($connection, app(OAuthClients::class)->for(MailProviderType::Google)))->accessToken();

    $stored = inTenant($this->tenant, fn () => $connection->fresh()->credentials);
    expect($token)->toBe('ya29.access-nuevo')
        ->and($stored->refreshToken)->toBe('1//refresh-original')
        ->and($stored->scopes)->toBe([GmailProvider::SCOPE_SEND]);
});

it('asks to reconnect when google revokes the access', function () {
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400)]);
    $connection = inTenant($this->tenant, fn () => MailConnection::factory()->google()->create([
        'credentials' => new OAuthCredentials('ya29.viejo', '1//revocado', CarbonImmutable::now()->subMinute()),
    ]));

    $manager = new OAuthTokenManager($connection, app(OAuthClients::class)->for(MailProviderType::Google));

    expect(fn () => $manager->accessToken())
        ->toThrow(MailAuthenticationException::class, 'Google ha rechazado o revocado');

    expect(inTenant($this->tenant, fn () => $connection->fresh()->status))->toBe(MailConnectionStatus::Failing);
});

it('renews expired tokens transparently when using the provider', function () {
    fakeGoogle();
    $connection = inTenant($this->tenant, fn () => MailConnection::factory()->google()->create([
        'credentials' => new OAuthCredentials('ya29.viejo', '1//refresh', CarbonImmutable::now()->subMinute(), [GmailProvider::SCOPE_SEND]),
    ]));

    expect(app(MailProviderFactory::class)->make($connection)->testConnection()->ok())->toBeTrue();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/profile')
        && $request->hasHeader('Authorization', 'Bearer ya29.access-nuevo'));
});
