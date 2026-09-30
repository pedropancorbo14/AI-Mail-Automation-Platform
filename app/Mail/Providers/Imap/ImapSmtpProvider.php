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
use App\Mail\Exceptions\MailProviderException;
use Closure;
use DirectoryTree\ImapEngine\Exceptions\Exception as ImapEngineException;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionException;
use DirectoryTree\ImapEngine\FolderInterface;

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

    public function messages(string $folder, int $limit = 50): array
    {
        throw new MailProviderException('Lectura de mensajes IMAP todavía no implementada.');
    }

    public function message(string $folder, string $id): MailMessage
    {
        throw new MailProviderException('Lectura de mensajes IMAP todavía no implementada.');
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
