<?php

use App\Mail\Data\MailFolder;
use App\Mail\Enums\MailProviderType;
use App\Mail\MailProviderFactory;
use App\Mail\Providers\Imap\ImapSmtpProvider;
use App\Models\MailConnection;
use App\Models\Tenant;
use DirectoryTree\ImapEngine\Testing\FakeFolder;
use Tests\Support\GreenMail;

it('lists the folders of the account', function () {
    $email = GreenMail::createAccount();
    $client = GreenMail::imapClient($email);
    $client->mailbox()->folders()->create('Archivo');
    $client->mailbox()->folders()->create('Ventas');

    $folders = (new ImapSmtpProvider($client))->folders();

    expect(array_map(fn (MailFolder $f) => $f->id, $folders))->toContain('INBOX', 'Archivo', 'Ventas');

    $inbox = collect($folders)->firstWhere('id', 'INBOX');
    expect($inbox->role)->toBe(MailFolder::INBOX)
        ->and(collect($folders)->firstWhere('id', 'Ventas')->role)->toBeNull();
})->group('greenmail');

it('maps special-use folder attributes to normalized roles', function (array $flags, ?string $role) {
    expect(ImapSmtpProvider::role(new FakeFolder('Carpeta', $flags)))->toBe($role);
})->with([
    'enviados' => [['\\HasNoChildren', '\\Sent'], MailFolder::SENT],
    'borradores' => [['\\Drafts'], MailFolder::DRAFTS],
    'papelera' => [['\\Trash'], MailFolder::TRASH],
    'spam' => [['\\Junk'], MailFolder::JUNK],
    'archivo' => [['\\Archive'], MailFolder::ARCHIVE],
    'mayúsculas/minúsculas' => [['\\SENT'], MailFolder::SENT],
    'normal' => [['\\HasNoChildren'], null],
]);

it('treats INBOX as the inbox regardless of case', function () {
    expect(ImapSmtpProvider::role(new FakeFolder('inbox')))->toBe(MailFolder::INBOX);
});

it('is the provider built by the factory for imap connections', function () {
    $tenant = Tenant::factory()->create();
    $connection = inTenant($tenant, fn () => MailConnection::factory()->create());

    expect(app(MailProviderFactory::class)->make($connection))->toBeInstanceOf(ImapSmtpProvider::class)
        ->and(app(MailProviderFactory::class)->supports(MailProviderType::Imap))->toBeTrue();
});
