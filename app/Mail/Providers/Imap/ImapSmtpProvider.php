<?php

namespace App\Mail\Providers\Imap;

use App\Mail\Contracts\MailProvider;
use App\Mail\Data\AttachmentContent;
use App\Mail\Data\ConnectionTestResult;
use App\Mail\Data\MailAddress;
use App\Mail\Data\MailFolder;
use App\Mail\Data\MailMessage;
use App\Mail\Data\NewMessages;
use App\Mail\Data\OutgoingMessage;
use App\Mail\Data\SentMessage;
use App\Mail\Exceptions\MailConnectionException;
use App\Mail\Exceptions\MailNotFoundException;
use App\Mail\Exceptions\MailProviderException;
use App\Mail\Support\MimeMessageBuilder;
use App\Mail\Support\MimeMessageMapper;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;
use DirectoryTree\ImapEngine\Exceptions\Exception as ImapEngineException;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionException;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\MessageInterface;
use DirectoryTree\ImapEngine\MessageQueryInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Proveedor para cualquier servidor de correo estándar: IMAP para leer y
 * SMTP para enviar.
 */
class ImapSmtpProvider implements MailProvider
{
    /** Atributos LIST de uso especial (RFC 6154) → rol normalizado. */
    private const array SPECIAL_USE = [
        '\\sent' => MailFolder::SENT,
        '\\drafts' => MailFolder::DRAFTS,
        '\\trash' => MailFolder::TRASH,
        '\\junk' => MailFolder::JUNK,
        '\\archive' => MailFolder::ARCHIVE,
    ];

    /**
     * Nombres habituales de las carpetas especiales, para servidores que no
     * anuncian los atributos de uso especial (RFC 6154). Sin distinguir
     * mayúsculas, solo el último tramo de la ruta.
     */
    private const array COMMON_NAMES = [
        'sent' => MailFolder::SENT, 'sent items' => MailFolder::SENT, 'sent messages' => MailFolder::SENT,
        'sent mail' => MailFolder::SENT, 'enviados' => MailFolder::SENT, 'elementos enviados' => MailFolder::SENT,
        'drafts' => MailFolder::DRAFTS, 'borradores' => MailFolder::DRAFTS,
        'trash' => MailFolder::TRASH, 'deleted items' => MailFolder::TRASH, 'deleted messages' => MailFolder::TRASH,
        'papelera' => MailFolder::TRASH, 'elementos eliminados' => MailFolder::TRASH,
        'junk' => MailFolder::JUNK, 'spam' => MailFolder::JUNK, 'junk e-mail' => MailFolder::JUNK,
        'junk email' => MailFolder::JUNK, 'correo no deseado' => MailFolder::JUNK,
    ];

    /**
     * @param  MailAddress  $from  La cuenta de la conexión: único remitente posible.
     * @param  bool  $saveSentCopy  Guardar en "Enviados" (IMAP APPEND) lo enviado por SMTP:
     *                              muchos servidores SMTP no lo hacen solos.
     */
    public function __construct(
        private readonly ImapClient $imap,
        private readonly SmtpClient $smtp,
        private readonly MailAddress $from,
        private readonly int $maxAttachmentBytes = 25 * 1024 * 1024,
        private readonly int $newMessagesBatch = 100,
        private readonly bool $saveSentCopy = false,
    ) {
    }

    /**
     * Prueba IMAP y SMTP por separado (son servidores y credenciales
     * distintos) y nunca lanza: el resultado dice qué parte falla y por qué.
     */
    public function testConnection(): ConnectionTestResult
    {
        $incomingError = self::errorOf(fn () => $this->imap->verify());
        $outgoingError = self::errorOf(fn () => $this->smtp->verify());

        $this->imap->disconnect();
        $this->smtp->disconnect();

        return new ConnectionTestResult(
            incomingOk: $incomingError === null,
            outgoingOk: $outgoingError === null,
            incomingError: $incomingError,
            outgoingError: $outgoingError,
        );
    }

    /** Mensaje de error apto para el usuario, o null si la operación fue bien. */
    private static function errorOf(Closure $check): ?string
    {
        try {
            $check();

            return null;
        } catch (MailProviderException $e) {
            return $e->getMessage();
        }
    }

    public function folders(): array
    {
        return $this->call(function () {
            $folders = [];

            foreach ($this->imap->mailbox()->folders()->get() as $folder) {
                // \Noselect: nodos contenedores de la jerarquía, sin mensajes.
                if (in_array('\\noselect', self::lowerFlags($folder), true)) {
                    continue;
                }

                $folders[] = new MailFolder($folder->path(), $folder->name(), self::role($folder));
            }

            return $folders;
        });
    }

    /**
     * Lectura con BODY.PEEK (por defecto en ImapEngine): leer NO marca el
     * mensaje como leído en el buzón del cliente.
     */
    public function messages(string $folder, int $limit = 50): array
    {
        return $this->call(function () use ($folder, $limit) {
            // newest() elige los últimos UIDs, pero la colección llega en orden
            // ascendente: se ordena explícitamente (UID crece con cada llegada).
            return $this->query($folder)
                ->newest()
                ->limit($limit)
                ->get()
                ->sortByDesc(fn (MessageInterface $message) => $message->uid())
                ->map(fn (MessageInterface $message) => ImapMessageMapper::toMailMessage($message, $folder))
                ->values()
                ->all();
        });
    }

    public function message(string $folder, string $id): MailMessage
    {
        return $this->call(function () use ($folder, $id) {
            return ImapMessageMapper::toMailMessage($this->findMessage($folder, $id), $folder);
        });
    }

    /**
     * $attachmentId es la posición del adjunto en el mensaje (ver
     * MimeMessageMapper), tal como aparece en MailMessage::$attachments.
     */
    public function downloadAttachment(string $folder, string $messageId, string $attachmentId): AttachmentContent
    {
        return $this->call(fn () => MimeMessageMapper::download(
            $this->findMessage($folder, $messageId),
            $messageId,
            $attachmentId,
            $this->maxAttachmentBytes,
        ));
    }

    /**
     * Cursor "v1:<UIDVALIDITY>:<último UID visto>". Los UID solo crecen
     * dentro de una carpeta mientras UIDVALIDITY no cambie; si cambia, el
     * servidor ha renumerado la carpeta y el cursor ya no sirve.
     */
    public function newMessages(string $folder, ?string $cursor): NewMessages
    {
        return $this->call(function () use ($folder, $cursor) {
            $status = array_change_key_case($this->folder($folder)->status(), CASE_UPPER);
            $validity = (int) $status['UIDVALIDITY'];
            $startingPoint = self::cursor($validity, max(0, (int) $status['UIDNEXT'] - 1));

            // Primera sincronización: solo se fija el punto de partida (sin histórico).
            if ($cursor === null) {
                return new NewMessages([], $startingPoint);
            }

            [$cursorValidity, $lastUid] = self::parseCursor($cursor);

            // Cursor ilegible o carpeta renumerada: nuevo punto de partida.
            if ($cursorValidity !== $validity) {
                return new NewMessages([], $startingPoint, resynced: true);
            }

            // 1) Solo UIDs (sin cuerpos): barato aunque haya miles de mensajes.
            //    "UID n:*" siempre incluye el último mensaje aunque su UID sea < n:
            //    por eso se filtra uid > $lastUid.
            $newUids = $this->folder($folder)->messages()
                ->uid($lastUid + 1, INF)
                ->get()
                ->map(fn (MessageInterface $message) => $message->uid())
                ->filter(fn (int $uid) => $uid > $lastUid)
                ->sort()
                ->values();

            // 2) Contenido completo solo del lote.
            $batchUids = $newUids->take($this->newMessagesBatch);
            $messages = $batchUids->isEmpty() ? collect() : $this->query($folder)
                ->uid($batchUids->all())
                ->get()
                ->sortBy(fn (MessageInterface $message) => $message->uid())
                ->values();

            return new NewMessages(
                messages: $messages->map(fn (MessageInterface $message) => ImapMessageMapper::toMailMessage($message, $folder))->all(),
                cursor: self::cursor($validity, $batchUids->last() ?? $lastUid),
                hasMore: $newUids->count() > $batchUids->count(),
            );
        });
    }

    public function send(OutgoingMessage $message): SentMessage
    {
        $email = MimeMessageBuilder::build($message, $this->from);
        $transport = $this->smtp->transport();

        try {
            $sent = $transport->send($email);
        } catch (TransportExceptionInterface $e) {
            if ($e->getCode() === 535) {
                throw $this->smtp->translate($e);
            }

            throw new MailProviderException(
                sprintf('El servidor SMTP rechazó el envío (código %d).', $e->getCode()),
                previous: $e,
            );
        }

        if ($this->saveSentCopy && $sent !== null) {
            $this->saveCopy($sent->toString());
        }

        return new SentMessage($sent?->getMessageId());
    }

    /**
     * Copia lo enviado en "Enviados", marcado como leído. El correo ya salió:
     * si la copia falla (sin carpeta, servidor caído) solo se avisa en el
     * log, nunca se trata como un envío fallido (se reintentaría y llegaría
     * dos veces).
     */
    private function saveCopy(string $raw): void
    {
        try {
            $this->call(function () use ($raw) {
                $sent = collect($this->imap->mailbox()->folders()->get())
                    ->first(fn (FolderInterface $folder) => self::role($folder) === MailFolder::SENT);

                if ($sent === null) {
                    Log::warning('No se guardó la copia del correo enviado: el buzón no tiene carpeta de enviados', ['from' => $this->from->email]);

                    return;
                }

                $sent->messages()->append($raw, ['\\Seen']);
            });
        } catch (Throwable $e) {
            Log::warning('No se pudo guardar la copia del correo enviado en Enviados', [
                'from' => $this->from->email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @throws MailNotFoundException
     */
    private function folder(string $folder): FolderInterface
    {
        return $this->imap->mailbox()->folders()->find($folder)
            ?? throw new MailNotFoundException("La carpeta {$folder} no existe.");
    }

    /** Consulta de mensajes con todo lo necesario para construir MailMessage. */
    private function query(string $folder): MessageQueryInterface
    {
        return $this->folder($folder)->messages()->withHeaders()->withBody()->withFlags();
    }

    /**
     * @throws MailNotFoundException
     */
    private function findMessage(string $folder, string $id): MessageInterface
    {
        $message = ctype_digit($id) ? $this->query($folder)->find((int) $id) : null;

        return $message ?? throw new MailNotFoundException("El mensaje {$id} no existe en {$folder}.");
    }

    private static function cursor(int $validity, int $lastUid): string
    {
        return "v1:{$validity}:{$lastUid}";
    }

    /** @return array{0: ?int, 1: int} [UIDVALIDITY, último UID]; [null, 0] si no es válido. */
    private static function parseCursor(string $cursor): array
    {
        if (! preg_match('/^v1:(\d+):(\d+)$/', $cursor, $matches)) {
            return [null, 0];
        }

        return [(int) $matches[1], (int) $matches[2]];
    }

    /**
     * INBOX es especial por nombre (RFC 9051); el resto por atributos de uso
     * especial o, si el servidor no los anuncia, por su nombre habitual.
     */
    public static function role(FolderInterface $folder): ?string
    {
        if (strcasecmp($folder->path(), 'INBOX') === 0) {
            return MailFolder::INBOX;
        }

        foreach (self::lowerFlags($folder) as $flag) {
            if (isset(self::SPECIAL_USE[$flag])) {
                return self::SPECIAL_USE[$flag];
            }
        }

        // Sin atributos (GreenMail, servidores antiguos): por su nombre habitual.
        return self::COMMON_NAMES[mb_strtolower(trim($folder->name()))] ?? null;
    }

    /** @return list<string> */
    private static function lowerFlags(FolderInterface $folder): array
    {
        return array_map(strtolower(...), $folder->flags());
    }

    /**
     * Ejecuta una operación IMAP traduciendo cualquier error de ImapEngine
     * a nuestras excepciones.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    private function call(Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (MailProviderException $e) {
            throw $e;
        } catch (ImapConnectionException $e) {
            throw new MailConnectionException('Se perdió la conexión con el servidor IMAP.', previous: $e);
        } catch (ImapEngineException $e) {
            throw new MailProviderException('El servidor IMAP devolvió un error al procesar la operación.', previous: $e);
        }
    }
}
