<?php

namespace App\Mail\Ingestion;

use App\Mail\Data\MailAddress;
use App\Mail\Data\MailAttachment;
use App\Mail\Data\MailMessage;
use App\Mail\Enums\RecipientType;
use App\Models\Email;
use App\Models\MailConnection;
use App\Tenancy\TenantService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Guarda en la BD los correos que devuelve un MailProvider. Idempotente:
 * el mismo correo del mismo buzón se guarda una sola vez (dedup_hash).
 * Se ejecuta dentro del tenant de la conexión (TenantService::runAs).
 */
class EmailIngestionService
{
    public function __construct(private readonly TenantService $tenants)
    {
    }

    /**
     * Devuelve el Email guardado; si ya existía, el existente
     * ($email->wasRecentlyCreated === false).
     */
    public function ingest(MailConnection $connection, MailMessage $message): Email
    {
        if ($this->tenants->id() !== $connection->tenant_id) {
            throw new LogicException('La ingesta debe ejecutarse dentro del tenant de la conexión.');
        }

        $hash = self::dedupHash($message);

        if ($existing = $this->find($connection, $hash)) {
            return $existing;
        }

        try {
            return DB::transaction(fn () => $this->store($connection, $message, $hash));
        } catch (UniqueConstraintViolationException) {
            // Otro worker lo ha guardado a la vez. Se captura fuera de la
            // transacción: en PostgreSQL un error la deja abortada.
            return $this->find($connection, $hash) ?? throw new LogicException('Email duplicado no encontrado.');
        }
    }

    /**
     * Identidad del correo dentro de un buzón: el Message-ID; si no lo
     * tiene, una huella de remitente, fecha, asunto y cuerpo. No se usa el
     * id del proveedor (el UID de IMAP cambia si cambia UIDVALIDITY).
     */
    public static function dedupHash(MailMessage $message): string
    {
        if ($message->messageId !== null && $message->messageId !== '') {
            return hash('sha256', 'mid:' . $message->messageId);
        }

        return hash('sha256', 'fp:' . json_encode([
            mb_strtolower((string) $message->from?->email),
            $message->date?->utc()->toIso8601String(),
            $message->subject,
            hash('sha256', (string) ($message->textBody ?? $message->htmlBody)),
        ]));
    }

    private function find(MailConnection $connection, string $hash): ?Email
    {
        return Email::query()
            ->where('mail_connection_id', $connection->id)
            ->where('dedup_hash', $hash)
            ->first();
    }

    private function store(MailConnection $connection, MailMessage $message, string $hash): Email
    {
        $email = new Email([
            'folder' => self::limit($message->folder),
            'provider_message_id' => self::limit($message->id),
            'message_id' => self::limit($message->messageId, 998),
            'in_reply_to' => self::limit($message->inReplyTo, 998),
            'references' => $message->references === [] ? null : array_map(fn (string $ref) => self::clean($ref), $message->references),
            'dedup_hash' => $hash,
            'from_email' => self::limit($message->from?->email),
            'from_name' => self::limit($message->from?->name),
            'subject' => self::clean($message->subject),
            'text_body' => self::clean($message->textBody),
            'html_body' => self::clean($message->htmlBody),
            'sent_at' => $message->date,
        ]);
        $email->mailConnection()->associate($connection);
        $email->save();

        $email->recipients()->createMany([
            ...self::recipients(RecipientType::To, $message->to),
            ...self::recipients(RecipientType::Cc, $message->cc),
            ...self::recipients(RecipientType::ReplyTo, $message->replyTo),
        ]);

        $email->attachments()->createMany(array_map(fn (MailAttachment $attachment) => [
            'provider_attachment_id' => self::limit($attachment->id),
            'filename' => self::limit($attachment->filename) ?: 'adjunto',
            'content_type' => self::limit($attachment->contentType) ?: 'application/octet-stream',
            'size' => max(0, $attachment->size),
            'inline' => $attachment->inline,
            'content_id' => self::limit($attachment->contentId),
        ], $message->attachments));

        return $email;
    }

    /**
     * @param  list<MailAddress>  $addresses
     * @return list<array<string, mixed>>
     */
    private static function recipients(RecipientType $type, array $addresses): array
    {
        return array_map(fn (MailAddress $address, int $position) => [
            'type' => $type,
            'email' => self::limit($address->email),
            'name' => self::limit($address->name),
            'position' => $position,
        ], $addresses, array_keys($addresses));
    }

    /**
     * Texto apto para PostgreSQL: UTF-8 válido y sin bytes NUL (un correo
     * mal codificado no puede impedir guardar el resto).
     */
    private static function clean(?string $value): ?string
    {
        return $value === null ? null : str_replace("\0", '', mb_scrub($value, 'UTF-8'));
    }

    /** Recorta al tamaño de la columna (varchar) en vez de fallar. */
    private static function limit(?string $value, int $length = 255): ?string
    {
        $value = self::clean($value);

        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
