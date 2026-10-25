<?php

use App\Mail\Data\MailFolder;
use App\Mail\Enums\MailConnectionStatus;
use App\Mail\Enums\MailProviderType;
use App\Mail\Exceptions\MailAuthenticationException;
use App\Mail\Exceptions\MailConnectionException;
use App\Mail\Ingestion\MailSyncService;
use App\Mail\MailProviderFactory;
use App\Models\Email;
use App\Models\MailConnection;
use App\Models\MailSyncState;
use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeMailProvider;

beforeEach(function () {
    Storage::fake('local');

    $this->tenant = Tenant::factory()->create();
    $this->connection = inTenant($this->tenant, fn () => MailConnection::factory()->create(['name' => 'Soporte']));
    $this->connection->forceFill(['status' => MailConnectionStatus::Active])->save();

    $this->provider = new FakeMailProvider(['INBOX' => [], 'Archivo' => []]);
    app(MailProviderFactory::class)->extend(MailProviderType::Imap, fn () => $this->provider);
});

/** Mensaje nuevo número $n en la bandeja de entrada del fake. */
function arriveInFake(int $n, array $overrides = []): void
{
    test()->provider->mailboxes['INBOX'][] = incomingMessage(array_merge([
        'id' => (string) $n,
        'messageId' => "msg-{$n}@cliente.test",
        'subject' => "Consulta {$n}",
        'attachments' => [],
    ], $overrides));
}

function syncMailbox()
{
    return app(MailSyncService::class)->sync(test()->connection->fresh());
}

function syncState(): MailSyncState
{
    return inTenant(test()->tenant, fn () => MailSyncState::sole());
}

function storedSubjects(): array
{
    return inTenant(test()->tenant, fn () => Email::orderBy('id')->pluck('subject')->all());
}

it('starts from the current inbox state without importing old mail', function () {
    arriveInFake(1);
    arriveInFake(2);

    $result = syncMailbox();

    expect($result->stored)->toBe(0)
        ->and(storedSubjects())->toBe([])
        ->and(syncState()->folder)->toBe('INBOX')
        ->and(syncState()->cursor)->toBe('2');
});

it('stores the new emails and moves the cursor', function () {
    syncMailbox();
    arriveInFake(1);
    arriveInFake(2);

    $result = syncMailbox();

    expect($result->stored)->toBe(2)
        ->and(storedSubjects())->toBe(['Consulta 1', 'Consulta 2'])
        ->and(syncState()->cursor)->toBe('2')
        ->and(syncState()->last_success_at)->not->toBeNull()
        ->and(inTenant($this->tenant, fn () => Email::whereNull('ingested_at')->count()))->toBe(0);

    // Sin correo nuevo no se guarda nada.
    expect(syncMailbox()->stored)->toBe(0);
});

it('finds the inbox by its role, not by its name', function () {
    $this->provider = new class(['Bandeja' => []]) extends FakeMailProvider
    {
        public function folders(): array
        {
            return [new MailFolder('Archivo', 'Archivo'), new MailFolder('Bandeja', 'Bandeja de entrada', MailFolder::INBOX)];
        }
    };

    syncMailbox();

    expect(syncState()->folder)->toBe('Bandeja');
});

it('brings big backlogs in batches up to the configured limit', function () {
    config(['mail_connections.sync_max_batches' => 2]);
    $this->provider->batchSize = 2;
    syncMailbox();
    foreach (range(1, 5) as $n) {
        arriveInFake($n);
    }

    $first = syncMailbox();
    $second = syncMailbox();

    expect([$first->stored, $first->hasMore])->toBe([4, true])
        ->and([$second->stored, $second->hasMore])->toBe([1, false])
        ->and(storedSubjects())->toBe(['Consulta 1', 'Consulta 2', 'Consulta 3', 'Consulta 4', 'Consulta 5']);
});

it('does not move the cursor when a batch fails, and repeats it without duplicates', function () {
    syncMailbox();
    arriveInFake(1);
    arriveInFake(2, ['attachments' => [new \App\Mail\Data\MailAttachment('1', 'a.pdf', 'application/pdf', 3)]]);
    $this->provider->failDownloadsWith = new MailConnectionException('Se perdió la conexión con el servidor.');

    expect(fn () => syncMailbox())->toThrow(MailConnectionException::class);

    // El correo 1 quedó guardado, pero el cursor no avanzó.
    expect(storedSubjects())->toBe(['Consulta 1', 'Consulta 2'])
        ->and(syncState()->cursor)->toBe('0')
        ->and(syncState()->last_error)->toBe('Se perdió la conexión con el servidor.')
        ->and(syncState()->consecutive_failures)->toBe(1);

    $this->provider->failDownloadsWith = null;
    $result = syncMailbox();

    expect([$result->stored, $result->duplicates])->toBe([0, 2])
        ->and(storedSubjects())->toBe(['Consulta 1', 'Consulta 2'])
        ->and(syncState()->cursor)->toBe('2')
        ->and(syncState()->last_error)->toBeNull()
        ->and(syncState()->consecutive_failures)->toBe(0)
        ->and(inTenant($this->tenant, fn () => Email::whereNull('ingested_at')->count()))->toBe(0);
});

it('marks the connection as failing when the account rejects the credentials', function () {
    syncMailbox();
    $this->provider->failNewMessagesWith = new MailAuthenticationException('El servidor rechazó el usuario o la contraseña.');

    expect(fn () => syncMailbox())->toThrow(MailAuthenticationException::class);

    $connection = $this->connection->fresh();
    expect($connection->status)->toBe(MailConnectionStatus::Failing)
        ->and($connection->last_error)->toBe('El servidor rechazó el usuario o la contraseña.')
        ->and(syncState()->last_error)->toBe('El servidor rechazó el usuario o la contraseña.');
});

it('does not mark the connection as failing on temporary errors', function () {
    syncMailbox();
    $this->provider->failNewMessagesWith = new MailConnectionException('Tiempo de espera agotado.');

    expect(fn () => syncMailbox())->toThrow(MailConnectionException::class);

    expect($this->connection->fresh()->status)->toBe(MailConnectionStatus::Active);
});

it('hides internal errors from the stored error message', function () {
    syncMailbox();
    $this->provider->failNewMessagesWith = new RuntimeException('SQLSTATE[08006] detalle interno');

    expect(fn () => syncMailbox())->toThrow(RuntimeException::class);

    expect(syncState()->last_error)->toBe('Error interno al sincronizar el buzón.');
});

it('records when the provider invalidated the cursor', function () {
    syncMailbox();
    inTenant($this->tenant, fn () => syncState()->recordSuccess('cursor-caducado'));

    $result = syncMailbox();

    expect($result->resynced)->toBeTrue()
        ->and(syncState()->resynced_at)->not->toBeNull()
        ->and(syncState()->cursor)->toBe('0');
});

it('does not sync disabled connections', function () {
    $this->connection->forceFill(['status' => MailConnectionStatus::Disabled])->save();

    syncMailbox();

    expect($this->provider->newMessagesCalls)->toBe([])
        ->and(inTenant($this->tenant, fn () => MailSyncState::count()))->toBe(0);
});

it('runs inside the connection tenant and restores the previous one', function () {
    $other = Tenant::factory()->create();
    syncMailbox();
    arriveInFake(1);

    inTenant($other, function () use ($other) {
        syncMailbox();

        expect(app(\App\Tenancy\TenantService::class)->id())->toBe($other->id)
            ->and(Email::count())->toBe(0);
    });

    expect(inTenant($this->tenant, fn () => Email::sole()->tenant_id))->toBe($this->tenant->id);
});

it('sets aside an email that always fails and keeps syncing the rest', function () {
    config(['mail_connections.sync_skip_after_failures' => 3]);
    syncMailbox();
    arriveInFake(1);
    arriveInFake(2, [
        'subject' => 'Correo imposible',
        'attachments' => [new \App\Mail\Data\MailAttachment('1', 'a.pdf', 'application/pdf', 3)],
    ]);
    arriveInFake(3);
    // Un error que no es de conexión ni de credenciales: un bug, un dato imposible.
    $this->provider->failDownloadsWith = new RuntimeException('detalle interno del fallo');

    // Los dos primeros intentos fallan y el cursor no se mueve.
    foreach ([1, 2] as $attempt) {
        expect(fn () => syncMailbox())->toThrow(RuntimeException::class);
        expect(syncState()->cursor)->toBe('0')
            ->and(syncState()->failing_message_id)->toBe('2')
            ->and(syncState()->failing_message_attempts)->toBe($attempt);
    }

    // Al tercero se aparta y el buzón sigue.
    $result = syncMailbox();

    expect($result->skipped)->toBe(1)
        ->and($result->stored)->toBe(1)
        ->and(storedSubjects())->toBe(['Consulta 1', 'Correo imposible', 'Consulta 3'])
        ->and(syncState()->cursor)->toBe('3')
        ->and(syncState()->consecutive_failures)->toBe(0)
        ->and(syncState()->failing_message_id)->toBeNull();

    $skipped = inTenant($this->tenant, fn () => \App\Models\MailSkippedMessage::sole());
    expect($skipped->provider_message_id)->toBe('2')
        ->and($skipped->folder)->toBe('INBOX')
        ->and($skipped->subject)->toBe('Correo imposible')
        ->and($skipped->message_id)->toBe('msg-2@cliente.test')
        ->and($skipped->attempts)->toBe(3)
        // Sin detalles internos (solo van al log).
        ->and($skipped->error)->toBe('Error interno al guardar el correo.');

    expect(metricSample('mail_messages_skipped_total', ['tenant' => $this->tenant->slug, 'provider' => 'imap']))->toBe(1.0);
});

it('skips an email already set aside if the provider sends it again', function () {
    config(['mail_connections.sync_skip_after_failures' => 1]);
    syncMailbox();
    arriveInFake(1, ['attachments' => [new \App\Mail\Data\MailAttachment('1', 'a.pdf', 'application/pdf', 3)]]);
    $this->provider->failDownloadsWith = new RuntimeException('bug');

    expect(syncMailbox()->skipped)->toBe(1);

    // El proveedor invalida el cursor y lo vuelve a entregar: se salta sin reintentar.
    inTenant($this->tenant, fn () => syncState()->recordSuccess('0'));
    $downloads = count($this->provider->downloads);

    expect(syncMailbox()->skipped)->toBe(1)
        ->and(count($this->provider->downloads))->toBe($downloads)
        ->and(inTenant($this->tenant, fn () => \App\Models\MailSkippedMessage::count()))->toBe(1);
});

it('never sets aside an email because of connection or credential errors', function (Throwable $error) {
    config(['mail_connections.sync_skip_after_failures' => 1]);
    syncMailbox();
    arriveInFake(1, ['attachments' => [new \App\Mail\Data\MailAttachment('1', 'a.pdf', 'application/pdf', 3)]]);
    $this->provider->failDownloadsWith = $error;

    expect(fn () => syncMailbox())->toThrow($error::class);

    expect(inTenant($this->tenant, fn () => \App\Models\MailSkippedMessage::count()))->toBe(0)
        ->and(syncState()->cursor)->toBe('0')
        ->and(syncState()->failing_message_attempts)->toBe(0);
})->with([
    'conexión' => [new MailConnectionException('Se perdió la conexión.')],
    'credenciales' => [new MailAuthenticationException('Contraseña rechazada.')],
]);

it('starts counting again when a different email fails', function () {
    config(['mail_connections.sync_skip_after_failures' => 2]);
    syncMailbox();
    arriveInFake(1, ['attachments' => [new \App\Mail\Data\MailAttachment('1', 'a.pdf', 'application/pdf', 3)]]);
    $this->provider->failDownloadsWith = new RuntimeException('bug');

    expect(fn () => syncMailbox())->toThrow(RuntimeException::class);
    expect(syncState()->failing_message_attempts)->toBe(1);

    inTenant($this->tenant, fn () => syncState()->recordMessageFailure('otro'));

    expect(syncState()->failing_message_id)->toBe('otro')
        ->and(syncState()->failing_message_attempts)->toBe(1);
});

it('does not set anything aside when the limit is 0', function () {
    config(['mail_connections.sync_skip_after_failures' => 0]);
    syncMailbox();
    arriveInFake(1, ['attachments' => [new \App\Mail\Data\MailAttachment('1', 'a.pdf', 'application/pdf', 3)]]);
    $this->provider->failDownloadsWith = new RuntimeException('bug');

    foreach (range(1, 3) as $i) {
        expect(fn () => syncMailbox())->toThrow(RuntimeException::class);
    }

    expect(inTenant($this->tenant, fn () => \App\Models\MailSkippedMessage::count()))->toBe(0)
        ->and(syncState()->consecutive_failures)->toBe(3);
});
