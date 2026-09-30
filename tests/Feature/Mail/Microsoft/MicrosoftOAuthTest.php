<?php

use App\Mail\Credentials\ImapSmtpCredentials;
use App\Mail\Credentials\OAuthCredentials;
use App\Mail\Enums\MailConnectionStatus;
use App\Mail\Enums\MailProviderType;
use App\Mail\Exceptions\MailAuthenticationException;
use App\Mail\MailProviderFactory;
use App\Mail\OAuth\MailOAuthService;
use App\Mail\OAuth\OAuthClients;
use App\Mail\OAuth\OAuthTokenManager;
use App\Models\MailConnection;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
 * Microsoft (login.microsoftonline.com y Graph) simulado con Http::fake.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();

    config([
        'services.microsoft.client_id' => 'client-id-de-prueba',
        'services.microsoft.client_secret' => 'client-secret-de-prueba',
        'services.microsoft.redirect_uri' => 'https://app.test/api/v1/mail-connections/oauth/microsoft/callback',
    ]);
    app()->forgetInstance(OAuthClients::class);

    $this->tenant = Tenant::factory()->create();
    $this->admin = User::factory()->for($this->tenant)->admin()->create();
});

function fakeMicrosoft(array $token = [], array $me = []): void
{
    Http::fake([
        'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response(array_merge([
            'access_token' => 'access-nuevo',
            'refresh_token' => 'refresh-nuevo',
            'expires_in' => 3600,
            'scope' => 'User.Read Mail.ReadWrite Mail.Send',
        ], $token)),
        'graph.microsoft.com/v1.0/me?*' => Http::response(array_merge([
            'displayName' => 'Ana Soporte',
            'mail' => 'Soporte@Contoso.test',
            'userPrincipalName' => 'soporte@contoso.test',
        ], $me)),
        'graph.microsoft.com/v1.0/me/mailFolders/inbox*' => Http::response(['id' => 'inbox']),
    ]);
}

/** Inicia el flujo como $user y devuelve [state, url]. */
function startMicrosoft(User $user, array $body = []): array
{
    $url = test()->actingAs($user, 'sanctum')
        ->postJson('/api/v1/mail-connections/oauth/microsoft/start', $body)
        ->assertOk()
        ->json('authorization_url');

    parse_str(parse_url($url, PHP_URL_QUERY), $query);

    return [$query['state'], $url, $query];
}

function callbackMicrosoft(array $query): Illuminate\Testing\TestResponse
{
    app('auth')->forgetGuards(); // el navegador vuelve sin nuestro token

    return test()->getJson('/api/v1/mail-connections/oauth/microsoft/callback?' . http_build_query($query));
}

// --- Inicio del flujo ------------------------------------------------------------

it('builds the microsoft authorization url with pkce and minimal scopes', function () {
    [$state, $url, $query] = startMicrosoft($this->admin);

    expect($url)->toStartWith('https://login.microsoftonline.com/common/oauth2/v2.0/authorize?')
        ->and($query['client_id'])->toBe('client-id-de-prueba')
        ->and($query['response_type'])->toBe('code')
        ->and($query['redirect_uri'])->toBe('https://app.test/api/v1/mail-connections/oauth/microsoft/callback')
        ->and(explode(' ', $query['scope']))->toBe(['offline_access', 'User.Read', 'Mail.ReadWrite', 'Mail.Send'])
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and(strlen($query['code_challenge']))->toBe(43)
        ->and(strlen($state))->toBe(40);
});

it('does not let agents start a connection', function () {
    $this->actingAs(User::factory()->for($this->tenant)->agent()->create(), 'sanctum')
        ->postJson('/api/v1/mail-connections/oauth/microsoft/start')
        ->assertForbidden();
});

it('explains when microsoft is not configured on the server', function () {
    config(['services.microsoft.client_id' => null]);
    app()->forgetInstance(OAuthClients::class);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/mail-connections/oauth/microsoft/start')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'La conexión con microsoft no está configurada en este servidor.');
});

it('only accepts oauth providers in the url', function (string $provider) {
    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/mail-connections/oauth/{$provider}/start")
        ->assertNotFound();
})->with(['imap', 'yahoo']);

// --- Callback --------------------------------------------------------------------

it('creates the connection in the company that started the flow', function () {
    fakeMicrosoft();
    [$state, , $authorize] = startMicrosoft($this->admin, ['name' => 'Soporte 365']);

    $response = callbackMicrosoft(['code' => 'codigo-autorizacion', 'state' => $state])
        ->assertCreated()
        ->assertJsonPath('data.provider', 'microsoft')
        ->assertJsonPath('data.email', 'soporte@contoso.test')
        ->assertJsonPath('data.name', 'Soporte 365')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.settings.scopes', ['User.Read', 'Mail.ReadWrite', 'Mail.Send']);

    expect($response->content())->not->toContain('access-nuevo')->not->toContain('refresh-nuevo');

    $connection = inTenant($this->tenant, fn () => MailConnection::findOrFail($response->json('data.id')));
    expect($connection->tenant_id)->toBe($this->tenant->id)
        ->and($connection->created_by)->toBe($this->admin->id)
        ->and($connection->credentials->accessToken)->toBe('access-nuevo')
        ->and($connection->credentials->refreshToken)->toBe('refresh-nuevo');

    // PKCE: el verificador enviado corresponde al challenge de la URL de autorización.
    Http::assertSent(function (Request $request) use ($authorize) {
        return str_contains($request->url(), '/oauth2/v2.0/token')
            && $request['grant_type'] === 'authorization_code'
            && $request['code'] === 'codigo-autorizacion'
            && $request['client_secret'] === 'client-secret-de-prueba'
            && MailOAuthService::codeChallenge($request['code_verifier']) === $authorize['code_challenge'];
    });
});

it('uses the microsoft display name when no name was given', function () {
    fakeMicrosoft();
    [$state] = startMicrosoft($this->admin);

    callbackMicrosoft(['code' => 'c', 'state' => $state])->assertJsonPath('data.name', 'Ana Soporte');
});

it('accepts each state only once', function () {
    fakeMicrosoft();
    [$state] = startMicrosoft($this->admin);

    callbackMicrosoft(['code' => 'c', 'state' => $state])->assertCreated();
    callbackMicrosoft(['code' => 'c', 'state' => $state])->assertUnprocessable();
});

it('rejects unknown or expired states', function () {
    fakeMicrosoft();
    [$state] = startMicrosoft($this->admin);

    callbackMicrosoft(['code' => 'c', 'state' => 'inventado'])->assertUnprocessable();

    $this->travel(11)->minutes();
    callbackMicrosoft(['code' => 'c', 'state' => $state])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'La autorización ha caducado o no es válida: vuelve a iniciar la conexión.');

    expect(inTenant($this->tenant, fn () => MailConnection::count()))->toBe(0);
});

it('handles the user cancelling the authorization', function () {
    [$state] = startMicrosoft($this->admin);

    callbackMicrosoft(['error' => 'access_denied', 'error_description' => 'user cancelled', 'state' => $state])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'No se ha concedido el acceso a la cuenta de correo.');
});

it('does not complete the flow if the user lost the permission meanwhile', function () {
    fakeMicrosoft();
    [$state] = startMicrosoft($this->admin);

    $this->admin->role()->associate(Role::where('name', Role::AGENT)->first())->save();

    callbackMicrosoft(['code' => 'c', 'state' => $state])->assertUnprocessable();
    expect(inTenant($this->tenant, fn () => MailConnection::count()))->toBe(0);
});

it('reconnects an existing account instead of duplicating it', function () {
    fakeMicrosoft(['access_token' => 'access-reconectado']);
    $existing = inTenant($this->tenant, fn () => MailConnection::factory()->microsoft()->create(['email' => 'soporte@contoso.test']));
    inTenant($this->tenant, fn () => $existing->forceFill(['status' => MailConnectionStatus::Failing, 'last_error' => 'revocado'])->save());

    [$state] = startMicrosoft($this->admin);
    callbackMicrosoft(['code' => 'c', 'state' => $state])->assertCreated()->assertJsonPath('data.id', $existing->id);

    $existing = inTenant($this->tenant, fn () => $existing->fresh());
    expect(inTenant($this->tenant, fn () => MailConnection::count()))->toBe(1)
        ->and($existing->credentials->accessToken)->toBe('access-reconectado')
        ->and($existing->status)->toBe(MailConnectionStatus::Pending)
        ->and($existing->last_error)->toBeNull();
});

it('refuses to take over an account already connected with another provider', function () {
    fakeMicrosoft();
    inTenant($this->tenant, fn () => MailConnection::factory()->create(['email' => 'soporte@contoso.test']));
    [$state] = startMicrosoft($this->admin);

    callbackMicrosoft(['code' => 'c', 'state' => $state])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'La cuenta soporte@contoso.test ya está conectada como imap.');
});

it('redirects back to the panel when configured', function () {
    fakeMicrosoft();
    config(['mail_connections.oauth_redirect_to' => 'https://panel.test/conexiones']);
    [$state] = startMicrosoft($this->admin);

    $location = callbackMicrosoft(['code' => 'c', 'state' => $state])->assertRedirect()->headers->get('Location');

    expect($location)->toStartWith('https://panel.test/conexiones?status=connected&connection_id=');
});

// --- Renovación del token ---------------------------------------------------------

function microsoftConnection(Tenant $tenant, CarbonImmutable $expiresAt, ?string $refresh = 'refresh-viejo'): MailConnection
{
    return inTenant($tenant, fn () => MailConnection::factory()->microsoft()->create([
        'credentials' => new OAuthCredentials('access-viejo', $refresh, $expiresAt, ['Mail.Send']),
    ]));
}

function tokenManager(MailConnection $connection): OAuthTokenManager
{
    return new OAuthTokenManager($connection, app(OAuthClients::class)->for(MailProviderType::Microsoft));
}

it('uses the stored token while it is valid', function () {
    $connection = microsoftConnection($this->tenant, CarbonImmutable::now()->addHour());

    expect(tokenManager($connection)->accessToken())->toBe('access-viejo');
    Http::assertNothingSent();
});

it('renews an expired token and stores the new one encrypted', function () {
    fakeMicrosoft(['refresh_token' => null]); // Microsoft no siempre devuelve refresh nuevo
    $connection = microsoftConnection($this->tenant, CarbonImmutable::now()->subMinute());

    expect(tokenManager($connection)->accessToken())->toBe('access-nuevo');

    $stored = inTenant($this->tenant, fn () => $connection->fresh()->credentials);
    expect($stored->accessToken)->toBe('access-nuevo')
        ->and($stored->refreshToken)->toBe('refresh-viejo') // se conserva el anterior
        ->and($stored->isExpired())->toBeFalse();

    Http::assertSent(fn (Request $request) => $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'refresh-viejo');
});

it('marks the connection as needing reconnection when microsoft revokes access', function () {
    Http::fake(['login.microsoftonline.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);
    $connection = microsoftConnection($this->tenant, CarbonImmutable::now()->subMinute());

    expect(fn () => tokenManager($connection)->accessToken())
        ->toThrow(MailAuthenticationException::class, 'vuelve a conectarla');

    $connection = inTenant($this->tenant, fn () => $connection->fresh());
    expect($connection->status)->toBe(MailConnectionStatus::Failing)
        ->and($connection->last_error)->toContain('vuelve a conectarla');
});

it('reuses a token renewed meanwhile by another worker', function () {
    Http::fake(); // cualquier petición sería un error: no debe renovar otra vez
    $connection = microsoftConnection($this->tenant, CarbonImmutable::now()->subMinute());
    $manager = tokenManager($connection);

    // Otro worker renovó y guardó un token nuevo mientras tanto.
    inTenant($this->tenant, fn () => MailConnection::find($connection->id)->forceFill([
        'credentials' => new OAuthCredentials('access-de-otro-worker', 'refresh-rotado', CarbonImmutable::now()->addHour()),
    ])->save());

    expect($manager->accessToken())->toBe('access-de-otro-worker');
    Http::assertNothingSent();
});

it('renews expired tokens transparently when using the provider', function () {
    fakeMicrosoft();
    $connection = microsoftConnection($this->tenant, CarbonImmutable::now()->subMinute());

    $result = app(MailProviderFactory::class)->make($connection)->testConnection();

    expect($result->incomingOk)->toBeTrue();
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'mailFolders/inbox')
        && $request->hasHeader('Authorization', 'Bearer access-nuevo'));
});
