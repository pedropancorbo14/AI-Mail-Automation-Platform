<?php

use App\Mail\Data\MailAddress;
use App\Mail\Data\MailAttachment;
use App\Mail\Data\MailMessage;
use App\Mail\Enums\AttachmentStatus;
use App\Mail\Enums\EmailStatus;
use App\Mail\Enums\RecipientType;
use App\Mail\Ingestion\EmailIngestionService;
use App\Models\Email;
use App\Models\MailConnection;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->connection = inTenant($this->tenant, fn () => MailConnection::factory()->create());
});

/** Mensaje tal como lo devuelve un MailProvider (sobrescribible por campo). */
function incomingMessage(array $overrides = []): MailMessage
{
    return new MailMessage(...array_merge([
        'id' => '42',
        'folder' => 'INBOX',
        'messageId' => 'pedido-45821@cliente.test',
        'subject' => '¿Dónde está mi pedido #45821?',
        'from' => new MailAddress('cliente@cliente.test', 'Cliente Pérez'),
        'to' => [new MailAddress('soporte@empresa.test', 'Soporte'), new MailAddress('ventas@empresa.test')],
        'cc' => [new MailAddress('jefe@cliente.test')],
        'replyTo' => [new MailAddress('respuestas@cliente.test')],
        'date' => CarbonImmutable::parse('2026-09-30 08:15:00', 'UTC'),
        'textBody' => 'Hola, ¿dónde está mi pedido?',
        'htmlBody' => '<p>Hola, ¿dónde está mi <b>pedido</b>?</p>',
        'attachments' => [
            new MailAttachment('1', 'foto.png', 'image/png', 2048, inline: true, contentId: 'foto1'),
            new MailAttachment('2', 'ticket.pdf', 'application/pdf', 4096),
        ],
        'inReplyTo' => 'anterior@empresa.test',
        'references' => ['inicio@cliente.test', 'anterior@empresa.test'],
    ], $overrides));
}

function ingestMessage(MailMessage $message, ?MailConnection $connection = null): Email
{
    $connection ??= test()->connection;

    return inTenant($connection->tenant, fn () => app(EmailIngestionService::class)->ingest($connection, $message));
}

it('stores the email with sender, subject, bodies, date and thread headers', function () {
    $email = inTenant($this->tenant, fn () => ingestMessage(incomingMessage())->fresh());

    expect($email->tenant_id)->toBe($this->tenant->id)
        ->and($email->mail_connection_id)->toBe($this->connection->id)
        ->and($email->folder)->toBe('INBOX')
        ->and($email->provider_message_id)->toBe('42')
        ->and($email->message_id)->toBe('pedido-45821@cliente.test')
        ->and($email->in_reply_to)->toBe('anterior@empresa.test')
        ->and($email->references)->toBe(['inicio@cliente.test', 'anterior@empresa.test'])
        ->and($email->from_email)->toBe('cliente@cliente.test')
        ->and($email->from_name)->toBe('Cliente Pérez')
        ->and($email->subject)->toBe('¿Dónde está mi pedido #45821?')
        ->and($email->text_body)->toBe('Hola, ¿dónde está mi pedido?')
        ->and($email->html_body)->toBe('<p>Hola, ¿dónde está mi <b>pedido</b>?</p>')
        ->and($email->sent_at->utc()->toDateTimeString())->toBe('2026-09-30 08:15:00')
        ->and($email->status)->toBe(EmailStatus::Received)
        ->and($email->ingested_at)->toBeNull();
});

it('stores the recipients in order', function () {
    $email = ingestMessage(incomingMessage());

    $recipients = inTenant($this->tenant, fn () => $email->recipients()->get());

    expect($recipients->map(fn ($r) => [$r->type, $r->email, $r->name, $r->position])->all())->toBe([
        [RecipientType::Cc, 'jefe@cliente.test', null, 0],
        [RecipientType::ReplyTo, 'respuestas@cliente.test', null, 0],
        [RecipientType::To, 'soporte@empresa.test', 'Soporte', 0],
        [RecipientType::To, 'ventas@empresa.test', null, 1],
    ]);
});

it('stores the attachments metadata as pending', function () {
    $email = ingestMessage(incomingMessage());

    $attachments = inTenant($this->tenant, fn () => $email->attachments()->get());

    expect($attachments->map(fn ($a) => [$a->provider_attachment_id, $a->filename, $a->content_type, $a->size, $a->inline, $a->content_id, $a->status])->all())->toBe([
        ['1', 'foto.png', 'image/png', 2048, true, 'foto1', AttachmentStatus::Pending],
        ['2', 'ticket.pdf', 'application/pdf', 4096, false, null, AttachmentStatus::Pending],
    ]);
});

it('does not store the same email twice', function () {
    $first = ingestMessage(incomingMessage());
    // Mismo Message-ID aunque cambie el id del proveedor (p. ej. UIDVALIDITY nueva).
    $second = ingestMessage(incomingMessage(['id' => '999']));

    expect($first->wasRecentlyCreated)->toBeTrue()
        ->and($second->wasRecentlyCreated)->toBeFalse()
        ->and($second->id)->toBe($first->id)
        ->and(inTenant($this->tenant, fn () => [Email::count(), $first->recipients()->count(), $first->attachments()->count()]))->toBe([1, 4, 2]);
});

it('deduplicates emails without message-id by their content', function () {
    $first = ingestMessage(incomingMessage(['messageId' => null]));
    $again = ingestMessage(incomingMessage(['messageId' => null, 'id' => '43']));
    $other = ingestMessage(incomingMessage(['messageId' => null, 'textBody' => 'Otro texto']));

    expect($again->id)->toBe($first->id)
        ->and($other->id)->not->toBe($first->id);
});

it('stores the same email in two mailboxes of the company', function () {
    $other = inTenant($this->tenant, fn () => MailConnection::factory()->create());

    ingestMessage(incomingMessage());
    ingestMessage(incomingMessage(), $other);

    expect(inTenant($this->tenant, fn () => Email::count()))->toBe(2);
});

it('keeps each company emails separate', function () {
    $foreign = inTenant(Tenant::factory()->create(), fn () => MailConnection::factory()->create());

    ingestMessage(incomingMessage());
    ingestMessage(incomingMessage(), $foreign);

    expect(inTenant($this->tenant, fn () => Email::count()))->toBe(1)
        ->and(inTenant($foreign->tenant, fn () => Email::count()))->toBe(1);
});

it('refuses to ingest outside the connection tenant', function () {
    inTenant(Tenant::factory()->create(), fn () => app(EmailIngestionService::class)->ingest($this->connection, incomingMessage()));
})->throws(LogicException::class);

it('stores badly encoded and oversized values instead of failing', function () {
    $email = ingestMessage(incomingMessage([
        'messageId' => str_repeat('m', 2000) . '@x.test',
        'subject' => "Asunto con NUL\0 y latin1 \xE9",
        'textBody' => "Cuerpo\0 roto \xFF\xFE",
        'from' => new MailAddress(str_repeat('a', 300) . '@x.test', str_repeat('N', 300)),
        'attachments' => [new MailAttachment('1', '', '', -1)],
    ]));

    [$email, $attachment] = inTenant($this->tenant, fn () => [$email->fresh(), $email->attachments()->first()]);

    expect(mb_strlen($email->message_id))->toBe(998)
        ->and($email->subject)->toBe('Asunto con NUL y latin1 ?')
        ->and($email->text_body)->toBe('Cuerpo roto ??')
        ->and(mb_strlen($email->from_email))->toBe(255)
        ->and($attachment->only('filename', 'content_type', 'size'))
        ->toBe(['filename' => 'adjunto', 'content_type' => 'application/octet-stream', 'size' => 0]);
});

it('stores emails without sender, date nor bodies', function () {
    $email = ingestMessage(incomingMessage([
        'from' => null, 'date' => null, 'textBody' => null, 'htmlBody' => null,
        'to' => [], 'cc' => [], 'replyTo' => [], 'attachments' => [], 'references' => [],
    ]));

    $email = inTenant($this->tenant, fn () => $email->fresh());

    expect($email->from_email)->toBeNull()
        ->and($email->sent_at)->toBeNull()
        ->and($email->text_body)->toBeNull()
        ->and($email->references)->toBeNull();
});
