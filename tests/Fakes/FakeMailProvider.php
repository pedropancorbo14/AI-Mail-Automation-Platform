<?php

namespace Tests\Fakes;

use App\Mail\Contracts\MailProvider;
use App\Mail\Data\AttachmentContent;
use App\Mail\Data\ConnectionTestResult;
use App\Mail\Data\MailFolder;
use App\Mail\Data\MailMessage;
use App\Mail\Data\NewMessages;
use App\Mail\Data\OutgoingMessage;
use App\Mail\Data\SentMessage;
use App\Mail\Exceptions\MailNotFoundException;

/**
 * Proveedor en memoria para tests del código que usa MailProvider
 * (servicios, jobs, endpoints) sin necesitar un servidor de correo.
 */
class FakeMailProvider implements MailProvider
{
    /** @var list<OutgoingMessage> */
    public array $sent = [];

    /** @var list<string> Descargas pedidas: "carpeta/mensaje/adjunto". */
    public array $downloads = [];

    /** Si se define, downloadAttachment() lanza esta excepción (simular fallos). */
    public ?\Throwable $failDownloadsWith = null;

    /** Si se define, newMessages() lanza esta excepción. */
    public ?\Throwable $failNewMessagesWith = null;

    /** @var list<array{0: string, 1: string|null}> Llamadas a newMessages(): [carpeta, cursor]. */
    public array $newMessagesCalls = [];

    /** Mensajes por lote en newMessages() (hasMore si quedan más). */
    public int $batchSize = PHP_INT_MAX;

    /**
     * @param  array<string, list<MailMessage>>  $mailboxes  carpeta => mensajes (más antiguo primero)
     * @param  array<string, string>  $attachments  "carpeta/mensaje/adjunto" => contenido
     */
    public function __construct(
        public array $mailboxes = ['INBOX' => []],
        public ConnectionTestResult $testResult = new ConnectionTestResult(true, true),
        public array $attachments = [],
    ) {
    }

    public function testConnection(): ConnectionTestResult
    {
        return $this->testResult;
    }

    public function folders(): array
    {
        return array_map(
            fn (string $folder) => new MailFolder($folder, $folder, $folder === 'INBOX' ? MailFolder::INBOX : null),
            array_keys($this->mailboxes),
        );
    }

    public function messages(string $folder, int $limit = 50): array
    {
        return array_slice(array_reverse($this->folder($folder)), 0, $limit);
    }

    public function message(string $folder, string $id): MailMessage
    {
        foreach ($this->folder($folder) as $message) {
            if ($message->id === $id) {
                return $message;
            }
        }

        throw new MailNotFoundException("Mensaje {$id} no encontrado.");
    }

    public function downloadAttachment(string $folder, string $messageId, string $attachmentId): AttachmentContent
    {
        $this->downloads[] = "{$folder}/{$messageId}/{$attachmentId}";

        if ($this->failDownloadsWith !== null) {
            throw $this->failDownloadsWith;
        }

        foreach ($this->message($folder, $messageId)->attachments as $attachment) {
            if ($attachment->id === $attachmentId) {
                return new AttachmentContent($attachment, $this->attachments["{$folder}/{$messageId}/{$attachmentId}"] ?? '');
            }
        }

        throw new MailNotFoundException("Adjunto {$attachmentId} no encontrado.");
    }

    /**
     * Cursor = número de mensajes ya vistos en la carpeta. Cursor no
     * numérico → resync (como un UIDVALIDITY distinto en IMAP).
     */
    public function newMessages(string $folder, ?string $cursor): NewMessages
    {
        $this->newMessagesCalls[] = [$folder, $cursor];

        if ($this->failNewMessagesWith !== null) {
            throw $this->failNewMessagesWith;
        }

        $messages = $this->folder($folder);

        if ($cursor === null || ! ctype_digit($cursor)) {
            return new NewMessages([], (string) count($messages), resynced: $cursor !== null);
        }

        $batch = array_slice($messages, (int) $cursor, $this->batchSize);
        $next = (int) $cursor + count($batch);

        return new NewMessages($batch, (string) $next, hasMore: $next < count($messages));
    }

    public function send(OutgoingMessage $message): SentMessage
    {
        $this->sent[] = $message;

        return new SentMessage('fake-' . count($this->sent) . '@fake.test');
    }

    /** @return list<MailMessage> */
    private function folder(string $folder): array
    {
        return $this->mailboxes[$folder] ?? throw new MailNotFoundException("Carpeta {$folder} no encontrada.");
    }
}
