<?php

use App\Mail\Autodetect\DnsLookup;
use App\Mail\Autodetect\MailAutodetector;
use App\Mail\Security\MailHostGuard;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/*
 * Autodetección de la configuración IMAP/SMTP. Sin red: DNS simulado
 * (DnsLookup + MailHostGuard) y HTTP simulado (Http::fake).
 */

const ISPDB_GMAIL = <<<'XML'
<?xml version="1.0"?>
<clientConfig version="1.1">
  <emailProvider id="googlemail.com">
    <domain>gmail.com</domain>
    <incomingServer type="pop3"><hostname>pop.gmail.com</hostname><port>995</port><socketType>SSL</socketType><username>%EMAILADDRESS%</username></incomingServer>
    <incomingServer type="imap"><hostname>imap.gmail.com</hostname><port>143</port><socketType>STARTTLS</socketType><username>%EMAILADDRESS%</username><authentication>password-cleartext</authentication></incomingServer>
    <incomingServer type="imap"><hostname>imap.gmail.com</hostname><port>993</port><socketType>SSL</socketType><username>%EMAILADDRESS%</username><authentication>OAuth2</authentication><authentication>password-cleartext</authentication></incomingServer>
    <incomingServer type="imap"><hostname>imap.oauth-only.test</hostname><port>993</port><socketType>SSL</socketType><username>%EMAILADDRESS%</username><authentication>OAuth2</authentication></incomingServer>
    <outgoingServer type="smtp"><hostname>smtp.gmail.com</hostname><port>465</port><socketType>SSL</socketType><username>%EMAILADDRESS%</username><authentication>password-cleartext</authentication></outgoingServer>
  </emailProvider>
</clientConfig>
XML;

/**
 * DNS simulado: $public = hosts que resuelven a una IP pública; el resto no
 * resuelve (o es interno). $mx y $srv por nombre.
 */
function fakeDns(array $public = [], array $mx = [], array $srv = [], array $internal = []): void
{
    test()->mock(MailHostGuard::class, function ($mock) use ($public, $internal) {
        $mock->shouldReceive('allowedIps')->andReturnUsing(function (string $host) use ($public, $internal) {
            if (in_array($host, $internal, true)) {
                return []; // apunta a una IP interna: MailHostGuard lo rechaza
            }

            return in_array($host, $public, true) ? ['93.184.216.34'] : [];
        });
    });

    app()->instance(DnsLookup::class, new class($mx, $srv) extends DnsLookup {
        public function __construct(private array $mxRecords, private array $srvRecords)
        {
        }

        public function mx(string $domain): array
        {
            return $this->mxRecords[$domain] ?? [];
        }

        public function srv(string $name): ?array
        {
            return $this->srvRecords[$name] ?? null;
        }
    });
}

function autodetect(string $email): array
{
    return app(MailAutodetector::class)->detect($email)->toArray();
}

beforeEach(function () {
    Http::preventStrayRequests();
});

it('uses the public thunderbird database, preferring ssl and password login', function () {
    fakeDns(public: ['autoconfig.thunderbird.net'], mx: ['gmail.com' => ['gmail-smtp-in.l.google.com']]);
    Http::fake(['autoconfig.thunderbird.net/v1.1/gmail.com' => Http::response(ISPDB_GMAIL)]);

    expect(autodetect('Ana.Perez@Gmail.com'))->toBe([
        'found' => true,
        'source' => 'ispdb',
        'provider_hint' => 'google',
        'imap' => ['host' => 'imap.gmail.com', 'port' => 993, 'encryption' => 'ssl', 'username' => 'ana.perez@gmail.com'],
        'smtp' => ['host' => 'smtp.gmail.com', 'port' => 465, 'encryption' => 'ssl', 'username' => 'ana.perez@gmail.com'],
    ]);
});

it('falls back to the autoconfig of the domain itself', function () {
    fakeDns(public: ['autoconfig.thunderbird.net', 'autoconfig.empresa.test']);
    Http::fake([
        'autoconfig.thunderbird.net/*' => Http::response('', 404),
        'autoconfig.empresa.test/*' => Http::response(str_replace(
            ['imap.gmail.com', 'smtp.gmail.com', '%EMAILADDRESS%'],
            ['mail.empresa.test', 'mail.empresa.test', '%EMAILLOCALPART%'],
            ISPDB_GMAIL,
        )),
    ]);

    $result = autodetect('soporte@empresa.test');

    expect($result['source'])->toBe('domain')
        ->and($result['imap'])->toBe(['host' => 'mail.empresa.test', 'port' => 993, 'encryption' => 'ssl', 'username' => 'soporte'])
        ->and($result['provider_hint'])->toBeNull();

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://autoconfig.empresa.test/mail/config-v1.1.xml?emailaddress=soporte%40empresa.test'));
});

it('uses the dns srv records when there is no autoconfig', function () {
    fakeDns(srv: [
        '_imaps._tcp.empresa.test' => ['host' => 'correo.empresa.test', 'port' => 993],
        '_submission._tcp.empresa.test' => ['host' => 'envio.empresa.test', 'port' => 587],
    ]);

    expect(autodetect('soporte@empresa.test'))->toMatchArray([
        'source' => 'srv',
        'imap' => ['host' => 'correo.empresa.test', 'port' => 993, 'encryption' => 'ssl', 'username' => 'soporte@empresa.test'],
        'smtp' => ['host' => 'envio.empresa.test', 'port' => 587, 'encryption' => 'starttls', 'username' => 'soporte@empresa.test'],
    ]);
});

it('guesses the usual host names only if they exist', function () {
    fakeDns(public: ['mail.empresa.test', 'smtp.empresa.test']);

    expect(autodetect('soporte@empresa.test'))->toMatchArray([
        'source' => 'guess',
        'imap' => ['host' => 'mail.empresa.test', 'port' => 993, 'encryption' => 'ssl', 'username' => 'soporte@empresa.test'],
        'smtp' => ['host' => 'smtp.empresa.test', 'port' => 465, 'encryption' => 'ssl', 'username' => 'soporte@empresa.test'],
    ]);
});

it('detects microsoft 365 domains to recommend oauth', function () {
    fakeDns(mx: ['empresa.test' => ['empresa-test.mail.protection.outlook.com']]);

    expect(autodetect('soporte@empresa.test'))->toBe([
        'found' => false, 'source' => null, 'provider_hint' => 'microsoft', 'imap' => null, 'smtp' => null,
    ]);
});

it('never fetches anything from hosts that point to the internal network (anti-ssrf)', function () {
    fakeDns(public: ['autoconfig.thunderbird.net'], internal: ['autoconfig.malo.test', 'malo.test', 'imap.malo.test', 'smtp.malo.test']);
    Http::fake(['autoconfig.thunderbird.net/*' => Http::response('', 404)]);

    expect(autodetect('x@malo.test')['found'])->toBeFalse();

    Http::assertSentCount(1); // solo la ISPDB
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'malo.test/'));
});

it('ignores answers that are not a mail config, too big or unencrypted', function (string $body) {
    fakeDns(public: ['autoconfig.thunderbird.net']);
    Http::fake(['autoconfig.thunderbird.net/*' => Http::response($body)]);

    expect(autodetect('ana@ejemplo.test')['found'])->toBeFalse();
})->with([
    'html' => ['<html><body>Hola</body></html>'],
    'xml roto' => ['<clientConfig><emailProvider>'],
    'demasiado grande' => [str_repeat('x', 70_000)],
    'sin cifrar' => [str_replace(['SSL', 'STARTTLS'], 'plain', ISPDB_GMAIL)],
]);

it('rejects invalid addresses without looking anything up', function (string $email) {
    fakeDns();

    expect(autodetect($email)['found'])->toBeFalse();
    Http::assertNothingSent();
})->with(['sin-arroba', 'a@localhost', 'a@-malo-.test']);

describe('endpoint', function () {
    beforeEach(function () {
        $this->tenant = Tenant::factory()->create();
        fakeDns(public: ['autoconfig.thunderbird.net']);
        Http::fake(['autoconfig.thunderbird.net/*' => Http::response(ISPDB_GMAIL)]);
    });

    it('proposes the configuration to administrators', function () {
        Sanctum::actingAs(User::factory()->admin()->for($this->tenant)->create());

        $this->postJson('/api/v1/mail-connections/autodetect', ['email' => 'ana@gmail.com'])
            ->assertOk()
            ->assertJsonPath('data.found', true)
            ->assertJsonPath('data.imap.host', 'imap.gmail.com');
    });

    it('requires the manage permission and a valid email', function () {
        Sanctum::actingAs(User::factory()->for($this->tenant)->create());
        $this->postJson('/api/v1/mail-connections/autodetect', ['email' => 'ana@gmail.com'])->assertForbidden();

        Sanctum::actingAs(User::factory()->admin()->for($this->tenant)->create());
        $this->postJson('/api/v1/mail-connections/autodetect', ['email' => 'no es un email'])->assertUnprocessable();
    });
});
