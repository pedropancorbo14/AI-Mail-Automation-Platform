<?php

use App\Mail\Data\MailFolder;
use App\Mail\Enums\MailConnectionStatus;
use App\Mail\Enums\MailProviderType;
use App\Mail\Exceptions\MailNotFoundException;
use App\Mail\Ingestion\MailSyncService;
use App\Mail\MailProviderFactory;
use App\Models\AuditLog;
use App\Models\Email;
use App\Models\MailConnection;
use App\Models\MailSyncState;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeMailProvider;

/*
 * Carpetas que se sincronizan: la bandeja de entrada siempre y las que elija
 * la empresa, nunca enviados, borradores, papelera ni spam.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->tenant = Tenant::factory()->create();
    $this->admin = User::factory()->for($this->tenant)->admin()->create();
    $this->agent = User::factory()->for($this->tenant)->agent()->create();
    $this->connection = inTenant($this->tenant, fn () => MailConnection::factory()->create(['name' => 'Soporte']));
    $this->connection->forceFill(['status' => MailConnectionStatus::Active])->save();

    $this->provider = new FakeMailProvider(['INBOX' => [], 'Pedidos web' => [], 'Enviados' => [], 'Spam' => []]);
    $this->provider->folderRoles = ['Enviados' => MailFolder::SENT, 'Spam' => MailFolder::JUNK];
    app(MailProviderFactory::class)->extend(MailProviderType::Imap, fn () => $this->provider);
});

function foldersUrl(): string
{
    return '/api/v1/mail-connections/' . test()->connection->id . '/folders';
}

function syncedFolders(): array
{
    return inTenant(test()->tenant, fn () => MailSyncState::orderBy('folder')->pluck('folder')->all());
}

it('lists the folders of the mailbox and which ones are synced', function () {
    $this->actingAs($this->agent, 'sanctum')->getJson(foldersUrl())
        ->assertOk()
        ->assertJsonPath('data.0', ['id' => 'INBOX', 'name' => 'INBOX', 'role' => 'inbox', 'selectable' => false, 'synced' => true, 'last_success_at' => null, 'last_error' => null])
        ->assertJsonPath('data.1.id', 'Pedidos web')
        ->assertJsonPath('data.1.selectable', true)
        ->assertJsonPath('data.1.synced', false)
        ->assertJsonPath('data.2.selectable', false)  // Enviados
        ->assertJsonPath('data.3.selectable', false); // Spam
});

it('adds and removes folders, always keeping the inbox', function () {
    $this->actingAs($this->admin, 'sanctum')->putJson(foldersUrl(), ['folders' => ['Pedidos web']])
        ->assertOk()
        ->assertJsonPath('data.1.synced', true);

    expect(syncedFolders())->toBe(['INBOX', 'Pedidos web']);

    $log = inTenant($this->tenant, fn () => AuditLog::where('action', 'mail_connection.folders_changed')->sole());
    expect($log->metadata)->toEqual(['added' => ['Pedidos web', 'INBOX'], 'removed' => []]);

    // Quitarla (la bandeja de entrada se queda aunque no se envíe).
    $this->actingAs($this->admin, 'sanctum')->putJson(foldersUrl(), ['folders' => []])->assertOk();

    expect(syncedFolders())->toBe(['INBOX']);
});

it('never syncs sent, drafts, trash or spam, nor unknown folders', function (string $folder, string $error) {
    $this->actingAs($this->admin, 'sanctum')->putJson(foldersUrl(), ['folders' => [$folder]])
        ->assertUnprocessable()
        ->assertJsonPath('errors.folders.0', $error);

    expect(syncedFolders())->toBe([]);
})->with([
    'enviados' => ['Enviados', 'La carpeta «Enviados» no se puede sincronizar (enviados, borradores, papelera y spam nunca se procesan).'],
    'spam' => ['Spam', 'La carpeta «Spam» no se puede sincronizar (enviados, borradores, papelera y spam nunca se procesan).'],
    'inexistente' => ['Otra', 'La carpeta «Otra» no existe en el buzón.'],
]);

it('limits the number of folders per mailbox', function () {
    config(['mail_connections.sync_max_folders' => 1]);

    $this->actingAs($this->admin, 'sanctum')->putJson(foldersUrl(), ['folders' => ['Pedidos web']])
        ->assertUnprocessable()
        ->assertJsonPath('errors.folders.0', 'Como máximo se sincronizan 1 carpetas por buzón (con la bandeja de entrada).');
});

it('says clearly when the provider cannot list the folders', function () {
    app(MailProviderFactory::class)->extend(MailProviderType::Imap, fn () => throw new \App\Mail\Exceptions\MailConnectionException('El servidor no responde.'));

    $this->actingAs($this->agent, 'sanctum')->getJson(foldersUrl())
        ->assertUnprocessable()
        ->assertJsonPath('message', 'No se pudieron leer las carpetas del buzón: El servidor no responde.');
});

it('only lets managers choose folders, and only in their company', function () {
    $this->actingAs($this->agent, 'sanctum')->putJson(foldersUrl(), ['folders' => []])->assertForbidden();

    $other = Tenant::factory()->create();
    $foreign = User::factory()->for($other)->admin()->create();
    $this->actingAs($foreign, 'sanctum')->getJson(foldersUrl())->assertNotFound();
    $this->actingAs($foreign, 'sanctum')->putJson(foldersUrl(), ['folders' => []])->assertNotFound();
});

it('syncs the chosen folders without history and keeps syncing when one fails', function () {
    inTenant($this->tenant, fn () => app(\App\Mail\Ingestion\MailFolderService::class)->set($this->connection, ['Pedidos web']));
    $sync = fn () => app(MailSyncService::class)->sync($this->connection->fresh());

    $this->provider->mailboxes['Pedidos web'][] = incomingMessage(['id' => '1', 'folder' => 'Pedidos web', 'messageId' => 'viejo@x.test', 'subject' => 'Antiguo']);
    $sync(); // punto de partida: no importa lo que ya había

    $this->provider->mailboxes['INBOX'][] = incomingMessage(['id' => '1', 'messageId' => 'a@x.test', 'subject' => 'En la bandeja']);
    $this->provider->mailboxes['Pedidos web'][] = incomingMessage(['id' => '2', 'folder' => 'Pedidos web', 'messageId' => 'b@x.test', 'subject' => 'En Pedidos web']);
    $sync();

    expect(inTenant($this->tenant, fn () => Email::orderBy('subject')->pluck('folder', 'subject')->all()))
        ->toBe(['En la bandeja' => 'INBOX', 'En Pedidos web' => 'Pedidos web']);

    // La carpeta desaparece del buzón: falla ella sola y la bandeja sigue.
    unset($this->provider->mailboxes['Pedidos web']);
    $this->provider->mailboxes['INBOX'][] = incomingMessage(['id' => '2', 'messageId' => 'c@x.test', 'subject' => 'Sigue llegando']);

    expect($sync)->toThrow(MailNotFoundException::class);

    expect(inTenant($this->tenant, fn () => Email::where('subject', 'Sigue llegando')->exists()))->toBeTrue()
        ->and(inTenant($this->tenant, fn () => MailSyncState::where('folder', 'Pedidos web')->sole()->consecutive_failures))->toBe(1)
        ->and(inTenant($this->tenant, fn () => MailSyncState::where('folder', 'INBOX')->sole()->consecutive_failures))->toBe(0);
});
