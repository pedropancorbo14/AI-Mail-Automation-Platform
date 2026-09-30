<?php

use App\Mail\Data\MailAttachment;
use App\Mail\Enums\AttachmentStatus;
use App\Mail\Exceptions\MailConnectionException;
use App\Mail\Exceptions\MailProviderException;
use App\Mail\Ingestion\EmailIngestionService;
use App\Mail\Ingestion\Events\EmailIngested;
use App\Models\Email;
use App\Models\MailConnection;
use App\Models\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeMailProvider;

beforeEach(function () {
    Storage::fake('local');
    Event::fake([EmailIngested::class]);

    $this->tenant = Tenant::factory()->create();
    $this->connection = inTenant($this->tenant, fn () => MailConnection::factory()->create());

    $this->pdf = "%PDF\x00\xff" . random_bytes(64);
    $this->message = incomingMessage([
        'attachments' => [
            new MailAttachment('1', '../../etc/passwd', 'text/plain', 5),
            new MailAttachment('2', 'ticket.pdf', 'application/pdf', strlen($this->pdf)),
        ],
    ]);
    $this->provider = new FakeMailProvider(['INBOX' => [$this->message]], attachments: [
        'INBOX/42/1' => 'hola!',
        'INBOX/42/2' => $this->pdf,
    ]);
});

/** Ingesta completa (guardar + adjuntos) dentro del tenant; devuelve el email recargado. */
function ingestFully(FakeMailProvider $provider, $message = null): Email
{
    $connection = test()->connection;

    return inTenant($connection->tenant, function () use ($provider, $message, $connection) {
        $service = app(EmailIngestionService::class);
        $email = $service->ingest($connection, $message ?? test()->message);
        $service->complete($provider, $email);

        return $email->fresh();
    });
}

function attachmentsOf(Email $email)
{
    return inTenant(test()->tenant, fn () => $email->attachments()->get());
}

it('stores the attachments byte by byte on the private disk', function () {
    $email = ingestFully($this->provider);

    $attachments = attachmentsOf($email);

    expect($attachments->pluck('status')->all())->toBe([AttachmentStatus::Stored, AttachmentStatus::Stored]);

    [$text, $pdf] = $attachments;
    expect(Storage::disk('local')->get($pdf->path))->toBe($this->pdf)
        ->and($pdf->disk)->toBe('local')
        ->and($pdf->sha256)->toBe(hash('sha256', $this->pdf))
        ->and($pdf->size)->toBe(strlen($this->pdf))
        // La ruta no usa el nombre del remitente.
        ->and($text->path)->toBe("tenants/{$this->tenant->id}/emails/{$email->id}/{$text->id}")
        ->and($text->filename)->toBe('../../etc/passwd');
});

it('marks the email as ingested and announces it once', function () {
    $this->freezeSecond();

    $email = ingestFully($this->provider);
    ingestFully($this->provider);

    expect($email->ingested_at->eq(now()))->toBeTrue();
    Event::assertDispatchedTimes(EmailIngested::class, 1);
    Event::assertDispatched(EmailIngested::class, fn (EmailIngested $e) => $e->emailId === $email->id && $e->tenantId === $this->tenant->id);
    expect($this->provider->downloads)->toHaveCount(2);
});

it('skips attachments bigger than the limit without downloading them', function () {
    config(['mail_connections.max_attachment_mb' => 1]);
    $message = incomingMessage(['attachments' => [new MailAttachment('1', 'video.mp4', 'video/mp4', 2 * 1024 * 1024)]]);

    $email = ingestFully(new FakeMailProvider(['INBOX' => [$message]]), $message);

    $attachment = attachmentsOf($email)->first();
    expect($attachment->status)->toBe(AttachmentStatus::Skipped)
        ->and($attachment->error)->toContain('1 MB')
        ->and($attachment->path)->toBeNull()
        ->and($email->ingested_at)->not->toBeNull();
});

it('records attachments the provider cannot deliver and goes on', function () {
    $this->provider->failDownloadsWith = new MailProviderException('Tipo de adjunto no soportado.');

    $email = ingestFully($this->provider);

    expect(attachmentsOf($email)->map(fn ($a) => [$a->status, $a->error])->all())->toBe([
        [AttachmentStatus::Failed, 'Tipo de adjunto no soportado.'],
        [AttachmentStatus::Failed, 'Tipo de adjunto no soportado.'],
    ])->and($email->ingested_at)->not->toBeNull();
});

it('leaves the ingestion unfinished on temporary errors and resumes it later', function () {
    $this->provider->failDownloadsWith = new MailConnectionException('No se pudo conectar con el servidor.');

    expect(fn () => ingestFully($this->provider))->toThrow(MailConnectionException::class);

    $email = inTenant($this->tenant, fn () => Email::sole());
    expect($email->ingested_at)->toBeNull()
        ->and(attachmentsOf($email)->pluck('status')->unique()->all())->toBe([AttachmentStatus::Pending]);
    Event::assertNotDispatched(EmailIngested::class);

    // Siguiente intento: el email ya existe (no se duplica) y se completa.
    $this->provider->failDownloadsWith = null;
    $email = ingestFully($this->provider);

    expect($email->ingested_at)->not->toBeNull()
        ->and(attachmentsOf($email)->pluck('status')->unique()->all())->toBe([AttachmentStatus::Stored])
        ->and(inTenant($this->tenant, fn () => Email::count()))->toBe(1);
    Event::assertDispatchedTimes(EmailIngested::class, 1);
});

it('marks emails without attachments as ingested directly', function () {
    $message = incomingMessage(['attachments' => []]);

    $email = ingestFully(new FakeMailProvider(['INBOX' => [$message]]), $message);

    expect($email->ingested_at)->not->toBeNull();
    Event::assertDispatched(EmailIngested::class);
});
