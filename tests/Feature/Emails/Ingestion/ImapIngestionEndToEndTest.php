<?php

use App\Mail\Enums\AttachmentStatus;
use App\Mail\Enums\EmailStatus;
use App\Mail\Enums\MailConnectionStatus;
use App\Mail\Enums\RecipientType;
use App\Models\Email;
use App\Models\MailConnection;
use App\Models\MailSyncState;
use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email as MimeEmail;
use Tests\Support\GreenMail;

/*
 * Ingesta completa contra un servidor IMAP real (GreenMail), tal como corre
 * en producción: scheduler (mail:sync) → FetchEmailsJob (cola sync en
 * tests) → MailSyncService → proveedor IMAP → BD + disco privado.
 */

beforeEach(function () {
    config(['mail_connections.allow_private_hosts' => true]);
    Storage::fake('local');

    $this->tenant = Tenant::factory()->create();
    $this->mailbox = GreenMail::createAccount();

    $this->connection = inTenant($this->tenant, fn () => MailConnection::factory()->create([
        'name' => 'Soporte Acme',
        'email' => $this->mailbox,
        'credentials' => GreenMail::credentials($this->mailbox),
    ]));
    $this->connection->forceFill(['status' => MailConnectionStatus::Active])->save();
});

function ingestionCustomerEmail(string $subject, ?string $messageId = null): MimeEmail
{
    $email = (new MimeEmail())
        ->from('"Cliente Pérez" <cliente@cliente.test>')
        ->to(test()->mailbox)
        ->cc('jefe@cliente.test')
        ->subject($subject)
        ->text("Texto de: {$subject}")
        ->html("<p>HTML de: <b>{$subject}</b></p>");

    if ($messageId) {
        $email->getHeaders()->addIdHeader('Message-ID', $messageId);
    }

    return $email;
}

it('ingests new emails from a real imap mailbox without duplicates', function () {
    // Correo anterior a conectar el buzón: no se importa.
    GreenMail::append($this->mailbox, ingestionCustomerEmail('Correo antiguo'));
    $this->artisan('mail:sync')->assertSuccessful();

    $pdf = "%PDF-1.4\x00\xff" . random_bytes(128);
    GreenMail::append($this->mailbox, ingestionCustomerEmail('¿Dónde está mi pedido #45821?', 'pedido-45821@cliente.test')
        ->attach($pdf, 'justificante.pdf', 'application/pdf'));
    GreenMail::append($this->mailbox, ingestionCustomerEmail('Quiero devolver un producto'));
    // El mismo correo entregado dos veces (mismo Message-ID).
    GreenMail::append($this->mailbox, ingestionCustomerEmail('¿Dónde está mi pedido #45821?', 'pedido-45821@cliente.test'));

    $this->artisan('mail:sync')->assertSuccessful();
    $this->artisan('mail:sync')->assertSuccessful();

    $emails = inTenant($this->tenant, fn () => Email::with('recipients', 'attachments')->orderBy('id')->get());

    expect($emails->pluck('subject')->all())->toBe(['¿Dónde está mi pedido #45821?', 'Quiero devolver un producto']);

    $order = $emails[0];
    expect($order->message_id)->toBe('pedido-45821@cliente.test')
        ->and($order->from_email)->toBe('cliente@cliente.test')
        ->and($order->from_name)->toBe('Cliente Pérez')
        ->and($order->text_body)->toContain('Texto de: ¿Dónde está mi pedido #45821?')
        ->and($order->html_body)->toContain('<b>¿Dónde está mi pedido #45821?</b>')
        ->and($order->sent_at)->not->toBeNull()
        // Al terminar la ingesta se procesa: queda publicado para ai-service.
        ->and($order->status)->toBe(EmailStatus::Processing)
        ->and($order->ingested_at)->not->toBeNull()
        ->and($order->recipients->map(fn ($r) => [$r->type, $r->email])->all())->toBe([
            [RecipientType::Cc, 'jefe@cliente.test'],
            [RecipientType::To, $this->mailbox],
        ]);

    $attachment = $order->attachments->sole();
    expect($attachment->status)->toBe(AttachmentStatus::Stored)
        ->and($attachment->filename)->toBe('justificante.pdf')
        ->and(Storage::disk('local')->get($attachment->path))->toBe($pdf);

    $state = inTenant($this->tenant, fn () => MailSyncState::sole());
    expect($state->folder)->toBe('INBOX')
        ->and($state->last_error)->toBeNull();

    // El buzón del cliente queda igual: los correos siguen sin leer.
    $inbox = GreenMail::provider($this->mailbox)->messages('INBOX');
    expect(collect($inbox)->pluck('seen')->unique()->all())->toBe([false]);
})->group('greenmail');

it('records the error and stops syncing when the password is wrong', function () {
    $this->artisan('mail:sync')->assertSuccessful();

    $this->connection->credentials = GreenMail::credentials($this->mailbox, 'clave-cambiada');
    $this->connection->save();
    GreenMail::append($this->mailbox, ingestionCustomerEmail('No llegará'));

    $this->artisan('mail:sync')->assertSuccessful();

    $connection = $this->connection->fresh();
    $state = inTenant($this->tenant, fn () => MailSyncState::sole());

    expect($connection->status)->toBe(MailConnectionStatus::Failing)
        ->and($connection->last_error)->toContain('rechazó')
        ->and($connection->last_error)->not->toContain('clave-cambiada')
        ->and($state->consecutive_failures)->toBe(1)
        ->and(inTenant($this->tenant, fn () => Email::count()))->toBe(0);

    // Con la conexión en failing el scheduler ya no la intenta.
    $this->artisan('mail:sync')->expectsOutput('Buzones encolados para sincronizar: 0');
})->group('greenmail');
