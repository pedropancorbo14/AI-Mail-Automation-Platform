<?php

use App\Mail\Credentials\EncryptedCredentials;
use App\Mail\Credentials\ImapSmtpCredentials;
use App\Mail\Credentials\OAuthCredentials;
use App\Mail\Enums\MailEncryption;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Modelo mínimo sobre la tabla real: el modelo MailConnection llega en la tarea siguiente.
class CredentialsHolder extends Model
{
    protected $table = 'mail_connections';

    protected $guarded = [];

    protected $casts = ['credentials' => EncryptedCredentials::class];
}

function imapCredentials(): ImapSmtpCredentials
{
    return new ImapSmtpCredentials(
        imapHost: 'imap.acme.test', imapPort: 993, imapEncryption: MailEncryption::Ssl,
        imapUsername: 'soporte@acme.test', imapPassword: 'super-secreta-imap',
        smtpHost: 'smtp.acme.test', smtpPort: 587, smtpEncryption: MailEncryption::StartTls,
        smtpUsername: 'soporte@acme.test', smtpPassword: 'super-secreta-smtp',
    );
}

function storeCredentials(mixed $credentials, string $provider = 'imap'): CredentialsHolder
{
    return CredentialsHolder::create([
        'tenant_id' => Tenant::factory()->create()->id,
        'name' => 'Soporte',
        'provider' => $provider,
        'email' => 'soporte@acme.test',
        'credentials' => $credentials,
    ]);
}

it('stores imap/smtp credentials encrypted and reads them back', function () {
    $holder = storeCredentials(imapCredentials());

    $raw = DB::table('mail_connections')->where('id', $holder->id)->value('credentials');
    expect($raw)->not->toContain('super-secreta')
        ->and($raw)->not->toContain('imap.acme.test');

    $read = CredentialsHolder::find($holder->id)->credentials;
    expect($read)->toBeInstanceOf(ImapSmtpCredentials::class)
        ->and($read->imapPassword)->toBe('super-secreta-imap')
        ->and($read->smtpEncryption)->toBe(MailEncryption::StartTls)
        ->and($read->imapPort)->toBe(993);
});

it('stores oauth credentials encrypted and reads them back', function () {
    $expires = CarbonImmutable::parse('2026-10-01 10:00:00');
    $holder = storeCredentials(new OAuthCredentials('access-xyz', 'refresh-xyz', $expires, ['Mail.Read']), 'microsoft');

    $read = CredentialsHolder::find($holder->id)->credentials;

    expect($read)->toBeInstanceOf(OAuthCredentials::class)
        ->and($read->accessToken)->toBe('access-xyz')
        ->and($read->refreshToken)->toBe('refresh-xyz')
        ->and($read->expiresAt->equalTo($expires))->toBeTrue()
        ->and($read->scopes)->toBe(['Mail.Read']);
});

it('detects tampered credentials instead of returning garbage', function () {
    $holder = storeCredentials(imapCredentials());
    $raw = DB::table('mail_connections')->where('id', $holder->id)->value('credentials');

    DB::table('mail_connections')->where('id', $holder->id)->update(['credentials' => substr($raw, 0, -4) . 'AAAA']);

    CredentialsHolder::find($holder->id)->credentials;
})->throws(DecryptException::class);

it('only accepts credential objects', function () {
    storeCredentials(['imap_password' => 'x']);
})->throws(InvalidArgumentException::class);

it('never leaks secrets when serialized, dumped or logged', function () {
    $imap = imapCredentials();
    $oauth = new OAuthCredentials('access-xyz', 'refresh-xyz', CarbonImmutable::now()->addHour());

    $outputs = [
        json_encode($imap),
        json_encode($oauth),
        print_r($imap, true),
        print_r($oauth, true),
        (function () use ($imap) {
            ob_start();
            var_dump($imap);

            return ob_get_clean();
        })(),
    ];

    foreach ($outputs as $output) {
        expect($output)->not->toContain('super-secreta')
            ->not->toContain('access-xyz')
            ->not->toContain('refresh-xyz');
    }

    expect(json_decode(json_encode($imap), true))
        ->imap_password->toBe('********')
        ->imap_host->toBe('imap.acme.test');

    Log::shouldReceive('info')->once()->withArgs(
        fn ($message, $context) => ! str_contains(json_encode($context), 'super-secreta'),
    );
    Log::info('conexión', ['credentials' => $imap]);
});

it('knows when an oauth token is expired or about to expire', function () {
    expect((new OAuthCredentials('a', 'r', CarbonImmutable::now()->addSeconds(30)))->isExpired())->toBeTrue()
        ->and((new OAuthCredentials('a', 'r', CarbonImmutable::now()->addHour()))->isExpired())->toBeFalse()
        ->and((new OAuthCredentials('a', 'r', CarbonImmutable::now()->subMinute()))->isExpired(0))->toBeTrue();
});
