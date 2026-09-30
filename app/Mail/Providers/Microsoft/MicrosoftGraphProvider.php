<?php

namespace App\Mail\Providers\Microsoft;

use App\Mail\Contracts\MailProvider;
use App\Mail\Data\AttachmentContent;
use App\Mail\Data\ConnectionTestResult;
use App\Mail\Data\MailFolder;
use App\Mail\Data\MailMessage;
use App\Mail\Data\NewMessages;
use App\Mail\Data\OutgoingMessage;
use App\Mail\Data\SentMessage;
use App\Mail\Exceptions\MailProviderException;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Cuentas de Microsoft 365 / Outlook.com a través de Microsoft Graph
 * (OAuth). No usa IMAP/SMTP: Microsoft los está retirando para cuentas de
 * empresa y Graph permite permisos acotados (Mail.ReadWrite, Mail.Send).
 */
class MicrosoftGraphProvider implements MailProvider
{
    /** Nombres conocidos de Graph → rol normalizado. */
    private const array WELL_KNOWN = [
        'inbox' => MailFolder::INBOX,
        'sentitems' => MailFolder::SENT,
        'drafts' => MailFolder::DRAFTS,
        'deleteditems' => MailFolder::TRASH,
        'junkemail' => MailFolder::JUNK,
        'archive' => MailFolder::ARCHIVE,
    ];

    /**
     * @param  list<string>  $scopes  Permisos concedidos al token (de OAuthCredentials).
     */
    public function __construct(
        private readonly GraphClient $graph,
        private readonly array $scopes = [],
        private readonly int $maxAttachmentBytes = 25 * 1024 * 1024,
        private readonly int $newMessagesBatch = 100,
    ) {
    }

    /**
     * Recepción: se lee la bandeja de entrada. Envío: no se puede probar sin
     * mandar un correo, así que se comprueba que el token tiene Mail.Send.
     */
    public function testConnection(): ConnectionTestResult
    {
        try {
            $this->graph->get('/me/mailFolders/inbox', ['$select' => 'id']);
            $incomingError = null;
        } catch (MailProviderException $e) {
            $incomingError = $e->getMessage();
        }

        $outgoingError = $this->hasScope('Mail.Send')
            ? null
            : 'La cuenta conectada no ha concedido el permiso de envío (Mail.Send): vuelve a conectarla.';

        return new ConnectionTestResult($incomingError === null, $outgoingError === null, $incomingError, $outgoingError);
    }

    /**
     * Carpetas de primer nivel y sus subcarpetas directas ("Padre/Hija").
     * Los ids son los de Graph; también se aceptan los nombres conocidos
     * (inbox, sentitems...) como $folder en el resto de operaciones.
     */
    public function folders(): array
    {
        $roles = $this->wellKnownRoles();
        $select = ['$top' => 100, '$select' => 'id,displayName,childFolderCount'];
        $folders = [];

        foreach ($this->graph->collect('/me/mailFolders', $select, 1000) as $folder) {
            $folders[] = new MailFolder($folder['id'], $folder['displayName'], $roles[$folder['id']] ?? null);

            if (($folder['childFolderCount'] ?? 0) === 0) {
                continue;
            }

            foreach ($this->graph->collect('/me/mailFolders/' . rawurlencode($folder['id']) . '/childFolders', $select, 1000) as $child) {
                $folders[] = new MailFolder($child['id'], "{$folder['displayName']}/{$child['displayName']}", $roles[$child['id']] ?? null);
            }
        }

        return $folders;
    }

    public function messages(string $folder, int $limit = 50): array
    {
        $items = $this->graph->collect(self::folderPath($folder) . '/messages', [
            '$top' => min($limit, 50),
            '$orderby' => 'receivedDateTime desc',
            '$select' => GraphMessageMapper::SELECT,
            '$expand' => GraphMessageMapper::EXPAND,
        ], $limit);

        return array_map(fn (array $message) => GraphMessageMapper::toMailMessage($message, $folder), $items);
    }

    public function message(string $folder, string $id): MailMessage
    {
        $message = $this->graph->get('/me/messages/' . rawurlencode($id), [
            '$select' => GraphMessageMapper::SELECT,
            '$expand' => GraphMessageMapper::EXPAND,
        ]);

        return GraphMessageMapper::toMailMessage($message, $folder);
    }

    public function downloadAttachment(string $folder, string $messageId, string $attachmentId): AttachmentContent
    {
        $path = '/me/messages/' . rawurlencode($messageId) . '/attachments/' . rawurlencode($attachmentId);

        // Primero solo metadatos: el tamaño se comprueba ANTES de descargar el contenido.
        $meta = $this->graph->get($path, ['$select' => 'id,name,contentType,size,isInline']);

        if (($meta['@odata.type'] ?? null) !== '#microsoft.graph.fileAttachment') {
            throw new MailProviderException('Este adjunto no es un fichero (es un correo adjunto o un enlace) y no se puede descargar.');
        }

        if ((int) ($meta['size'] ?? 0) > $this->maxAttachmentBytes) {
            throw new MailProviderException(sprintf(
                'El adjunto %s supera el tamaño máximo permitido (%d MB).',
                $meta['name'] ?? $attachmentId,
                intdiv($this->maxAttachmentBytes, 1024 * 1024),
            ));
        }

        $attachment = $this->graph->get($path);

        return new AttachmentContent(
            GraphMessageMapper::attachment($attachment),
            base64_decode($attachment['contentBytes'] ?? '', true) ?: '',
        );
    }

    /**
     * Cursor = receivedDateTime del último mensaje entregado + ids ya
     * entregados con esa misma fecha (Graph filtra con precisión de segundo:
     * "ge" + ids vistos evita perder o repetir mensajes del mismo segundo).
     *
     * Limitación: un mensaje movido a la carpeta desde otra conserva su fecha
     * de recepción antigua y no se detecta (el delta query de Graph sí lo haría).
     */
    public function newMessages(string $folder, ?string $cursor): NewMessages
    {
        $state = $cursor === null ? null : self::parseCursor($cursor);

        if ($state === null) {
            // Primera sincronización (o cursor ilegible): punto de partida = ahora, sin histórico.
            $startingPoint = self::cursor(CarbonImmutable::now('UTC')->startOfSecond(), []);

            return new NewMessages([], $startingPoint, resynced: $cursor !== null);
        }

        [$since, $seen] = $state;

        $page = $this->graph->get(self::folderPath($folder) . '/messages', [
            '$filter' => 'receivedDateTime ge ' . $since->format('Y-m-d\TH:i:s\Z'),
            '$orderby' => 'receivedDateTime asc',
            '$top' => $this->newMessagesBatch + count($seen),
            '$select' => GraphMessageMapper::SELECT,
            '$expand' => GraphMessageMapper::EXPAND,
        ]);

        $unseen = array_values(array_filter($page['value'] ?? [], fn (array $m) => ! in_array($m['id'], $seen, true)));
        $batch = array_slice($unseen, 0, $this->newMessagesBatch);

        if ($batch === []) {
            return new NewMessages([], $cursor);
        }

        // Nuevo cursor: fecha del último entregado + ids entregados en ese mismo segundo.
        $last = CarbonImmutable::parse(end($batch)['receivedDateTime'])->utc();
        $sameSecond = $last->equalTo($since) ? $seen : [];

        foreach ($batch as $message) {
            if (CarbonImmutable::parse($message['receivedDateTime'])->utc()->equalTo($last)) {
                $sameSecond[] = $message['id'];
            }
        }

        return new NewMessages(
            messages: array_map(fn (array $message) => GraphMessageMapper::toMailMessage($message, $folder), $batch),
            cursor: self::cursor($last, $sameSecond),
            hasMore: count($unseen) > count($batch) || ! empty($page['@odata.nextLink']),
        );
    }

    public function send(OutgoingMessage $message): SentMessage
    {
        throw new MailProviderException('Envío por Microsoft todavía no implementado.');
    }

    /** Ids de las carpetas especiales → rol, en una sola llamada $batch. */
    private function wellKnownRoles(): array
    {
        $requests = [];

        foreach (array_keys(self::WELL_KNOWN) as $alias) {
            $requests[$alias] = "/me/mailFolders/{$alias}?\$select=id";
        }

        $roles = [];

        foreach ($this->graph->batch($requests) as $alias => $response) {
            // Una carpeta especial puede no existir (p. ej. "archive"): se ignora.
            if ($response['status'] === 200 && isset($response['body']['id'])) {
                $roles[$response['body']['id']] = self::WELL_KNOWN[$alias];
            }
        }

        return $roles;
    }

    private static function folderPath(string $folder): string
    {
        return '/me/mailFolders/' . rawurlencode($folder);
    }

    /** @param  list<string>  $seen */
    private static function cursor(CarbonImmutable $since, array $seen): string
    {
        return 'v1:' . base64_encode(json_encode([
            'since' => $since->utc()->toIso8601ZuluString(),
            'seen' => array_values(array_unique($seen)),
        ]));
    }

    /** @return array{0: CarbonImmutable, 1: list<string>}|null */
    private static function parseCursor(string $cursor): ?array
    {
        if (! str_starts_with($cursor, 'v1:')) {
            return null;
        }

        $data = json_decode((string) base64_decode(substr($cursor, 3), true), true);

        if (! is_array($data) || ! isset($data['since'], $data['seen']) || ! is_array($data['seen'])) {
            return null;
        }

        try {
            return [CarbonImmutable::parse($data['since'])->utc(), array_values(array_filter($data['seen'], 'is_string'))];
        } catch (Throwable) {
            return null;
        }
    }

    /** Los scopes pueden venir como "Mail.Send" o "https://graph.microsoft.com/Mail.Send". */
    private function hasScope(string $scope): bool
    {
        foreach ($this->scopes as $granted) {
            if (strcasecmp(basename($granted), $scope) === 0) {
                return true;
            }
        }

        return false;
    }
}
