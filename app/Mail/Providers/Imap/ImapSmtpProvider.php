<?php

namespace App\Mail\Providers\Imap;

use App\Mail\Contracts\MailProvider;
use App\Mail\Data\AttachmentContent;
use App\Mail\Data\ConnectionTestResult;
use App\Mail\Data\MailFolder;
use App\Mail\Data\MailMessage;
use App\Mail\Data\NewMessages;
use App\Mail\Data\OutgoingMessage;
use App\Mail\Data\SentMessage;
use App\Mail\Exceptions\MailConnectionException;
use App\Mail\Exceptions\MailNotFoundException;
use App\Mail\Exceptions\MailProviderException;
use Closure;
use DirectoryTree\ImapEngine\Exceptions\Exception as ImapEngineException;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionException;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\MessageInterface;
use DirectoryTree\ImapEngine\MessageQueryInterface;

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

    public function __construct(
        private readonly ImapClient $imap,
    ) {
    }

    public function testConnection(): ConnectionTestResult
    {
        throw new MailProviderException('Prueba de conexión IMAP/SMTP todavía no implementada.');
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

    public function downloadAttachment(string $folder, string $messageId, string $attachmentId): AttachmentContent
    {
        throw new MailProviderException('Descarga de adjuntos IMAP todavía no implementada.');
    }

    public function newMessages(string $folder, ?string $cursor): NewMessages
    {
        throw new MailProviderException('Detección de nuevos emails IMAP todavía no implementada.');
    }

    public function send(OutgoingMessage $message): SentMessage
    {
        throw new MailProviderException('Envío SMTP todavía no implementado.');
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

    /** INBOX es especial por nombre (RFC 9051); el resto por atributos de uso especial. */
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

        return null;
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
