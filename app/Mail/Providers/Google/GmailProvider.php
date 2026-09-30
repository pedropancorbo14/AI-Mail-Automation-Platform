<?php

namespace App\Mail\Providers\Google;

use App\Mail\Contracts\MailProvider;
use App\Mail\Data\AttachmentContent;
use App\Mail\Data\ConnectionTestResult;
use App\Mail\Data\MailFolder;
use App\Mail\Data\MailMessage;
use App\Mail\Data\NewMessages;
use App\Mail\Data\OutgoingMessage;
use App\Mail\Data\SentMessage;
use App\Mail\Exceptions\MailNotFoundException;
use App\Mail\Exceptions\MailProviderException;
use App\Mail\Support\MimeMessageMapper;
use DirectoryTree\ImapEngine\FileMessage;

/**
 * Cuentas de Gmail / Google Workspace a través de la Gmail API (OAuth),
 * con permisos acotados: gmail.readonly (leer) y gmail.send (enviar).
 */
class GmailProvider implements MailProvider
{
    public const string SCOPE_READ = 'https://www.googleapis.com/auth/gmail.readonly';

    public const string SCOPE_SEND = 'https://www.googleapis.com/auth/gmail.send';

    /** Etiquetas de sistema que funcionan como carpeta → rol normalizado. */
    private const array SYSTEM_FOLDERS = [
        'INBOX' => MailFolder::INBOX,
        'SENT' => MailFolder::SENT,
        'DRAFT' => MailFolder::DRAFTS,
        'TRASH' => MailFolder::TRASH,
        'SPAM' => MailFolder::JUNK,
    ];

    /**
     * @param  list<string>  $scopes  Permisos concedidos al token (de OAuthCredentials).
     */
    public function __construct(
        private readonly GmailClient $gmail,
        private readonly array $scopes = [],
        private readonly int $maxAttachmentBytes = 25 * 1024 * 1024,
        private readonly int $newMessagesBatch = 100,
    ) {
    }

    /**
     * Recepción: se lee el perfil del buzón. Envío: no se puede probar sin
     * mandar un correo, así que se comprueba que el token tiene gmail.send.
     */
    public function testConnection(): ConnectionTestResult
    {
        try {
            $this->gmail->get('/profile');
            $incomingError = null;
        } catch (MailProviderException $e) {
            $incomingError = $e->getMessage();
        }

        $outgoingError = in_array(self::SCOPE_SEND, $this->scopes, true)
            ? null
            : 'La cuenta conectada no ha concedido el permiso de envío (gmail.send): vuelve a conectarla.';

        return new ConnectionTestResult($incomingError === null, $outgoingError === null, $incomingError, $outgoingError);
    }

    /**
     * En Gmail las carpetas son etiquetas. Se devuelven las de sistema que
     * actúan como carpeta (con su rol) y las creadas por el usuario; el
     * resto de etiquetas de sistema (UNREAD, STARRED, CATEGORY_*...) no.
     */
    public function folders(): array
    {
        $folders = [];

        foreach ($this->gmail->get('/labels')['labels'] ?? [] as $label) {
            $isSystem = ($label['type'] ?? 'user') === 'system';

            if ($isSystem && ! isset(self::SYSTEM_FOLDERS[$label['id']])) {
                continue;
            }

            $folders[] = new MailFolder($label['id'], $label['name'], $isSystem ? self::SYSTEM_FOLDERS[$label['id']] : null);
        }

        return $folders;
    }

    /** Gmail lista del más reciente al más antiguo; el contenido se pide en MIME crudo. */
    public function messages(string $folder, int $limit = 50): array
    {
        $ids = [];
        $pageToken = null;

        do {
            $page = $this->gmail->get('/messages', array_filter([
                'labelIds' => $folder,
                'maxResults' => min($limit, 100),
                'pageToken' => $pageToken,
            ]));

            $ids = [...$ids, ...array_column($page['messages'] ?? [], 'id')];
            $pageToken = $page['nextPageToken'] ?? null;
        } while ($pageToken && count($ids) < $limit);

        return array_map(fn (string $id) => $this->message($folder, $id), array_slice($ids, 0, $limit));
    }

    public function message(string $folder, string $id): MailMessage
    {
        [$mime, $labels] = $this->raw($id);

        return MimeMessageMapper::toMailMessage($mime, $folder, $id, seen: ! in_array('UNREAD', $labels, true));
    }

    /** Mismo criterio de ids que IMAP: posición del adjunto en el MIME (ver MimeMessageMapper). */
    public function downloadAttachment(string $folder, string $messageId, string $attachmentId): AttachmentContent
    {
        [$mime] = $this->raw($messageId);

        return MimeMessageMapper::download($mime, $messageId, $attachmentId, $this->maxAttachmentBytes);
    }

    /**
     * History API de Gmail. Cursor "v1:" + base64(json {history, pending}):
     * historyId desde el que preguntar + ids ya detectados que no cupieron
     * en el lote anterior. historyId demasiado antiguo (404) → resync.
     */
    public function newMessages(string $folder, ?string $cursor): NewMessages
    {
        $state = $cursor === null ? null : self::parseCursor($cursor);

        if ($state === null) {
            // Primera sincronización (o cursor ilegible): punto de partida actual, sin histórico.
            return new NewMessages([], self::cursor($this->currentHistoryId(), []), resynced: $cursor !== null);
        }

        [$historyId, $pending] = $state;

        if (count($pending) < $this->newMessagesBatch) {
            try {
                [$added, $historyId] = $this->addedSince($folder, $historyId);
            } catch (MailNotFoundException) {
                return new NewMessages([], self::cursor($this->currentHistoryId(), []), resynced: true);
            }

            $pending = array_values(array_unique([...$pending, ...$added]));
        }

        $batch = array_slice($pending, 0, $this->newMessagesBatch);
        $messages = [];

        foreach ($batch as $id) {
            try {
                $messages[] = $this->message($folder, $id);
            } catch (MailNotFoundException) {
                // Borrado entre la detección y la lectura: se ignora.
            }
        }

        return new NewMessages(
            messages: $messages,
            cursor: self::cursor($historyId, array_slice($pending, count($batch))),
            hasMore: count($pending) > count($batch),
        );
    }

    /**
     * Ids de mensajes añadidos a la carpeta desde $historyId (en orden) y el
     * historyId más reciente.
     *
     * @return array{0: list<string>, 1: string}
     *
     * @throws MailNotFoundException historyId demasiado antiguo.
     */
    private function addedSince(string $folder, string $historyId): array
    {
        $ids = [];
        $latest = $historyId;
        $pageToken = null;

        do {
            $page = $this->gmail->get('/history', array_filter([
                'startHistoryId' => $historyId,
                'historyTypes' => 'messageAdded',
                'labelId' => $folder,
                'pageToken' => $pageToken,
            ]));

            foreach ($page['history'] ?? [] as $entry) {
                foreach ($entry['messagesAdded'] ?? [] as $added) {
                    $ids[] = $added['message']['id'];
                }
            }

            $latest = $page['historyId'] ?? $latest;
            $pageToken = $page['nextPageToken'] ?? null;
        } while ($pageToken);

        return [array_values(array_unique($ids)), (string) $latest];
    }

    private function currentHistoryId(): string
    {
        return (string) $this->gmail->get('/profile')['historyId'];
    }

    /**
     * Mensaje en MIME crudo (format=raw) ya parseado, y sus etiquetas.
     *
     * @return array{0: FileMessage, 1: list<string>}
     */
    private function raw(string $id): array
    {
        $response = $this->gmail->get('/messages/' . rawurlencode($id), ['format' => 'raw']);

        return [new FileMessage(self::base64UrlDecode($response['raw'] ?? '')), $response['labelIds'] ?? []];
    }

    private static function base64UrlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'));
    }

    /** @param  list<string>  $pending */
    private static function cursor(string $historyId, array $pending): string
    {
        return 'v1:' . base64_encode(json_encode(['history' => $historyId, 'pending' => array_values($pending)]));
    }

    /** @return array{0: string, 1: list<string>}|null */
    private static function parseCursor(string $cursor): ?array
    {
        if (! str_starts_with($cursor, 'v1:')) {
            return null;
        }

        $data = json_decode((string) base64_decode(substr($cursor, 3), true), true);

        if (! is_array($data) || ! isset($data['history']) || ! ctype_digit((string) $data['history']) || ! is_array($data['pending'] ?? null)) {
            return null;
        }

        return [(string) $data['history'], array_values(array_filter($data['pending'], 'is_string'))];
    }

    public function send(OutgoingMessage $message): SentMessage
    {
        throw new MailProviderException('Envío por Gmail todavía no implementado.');
    }
}
