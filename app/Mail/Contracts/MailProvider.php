<?php

namespace App\Mail\Contracts;

use App\Mail\Data\AttachmentContent;
use App\Mail\Data\ConnectionTestResult;
use App\Mail\Data\MailFolder;
use App\Mail\Data\MailMessage;
use App\Mail\Data\NewMessages;
use App\Mail\Data\OutgoingMessage;
use App\Mail\Data\SentMessage;
use App\Mail\Exceptions\MailProviderException;

/**
 * Contrato común para cualquier proveedor de correo (IMAP/SMTP, Microsoft
 * Graph, Gmail API). El resto de la aplicación solo trabaja con esta
 * interfaz y con los DTOs de App\Mail\Data: nunca con clases de una
 * librería o API concreta.
 *
 * Identificadores: $folder es la ruta/id de carpeta que devuelve folders()
 * y $id el identificador del mensaje dentro del proveedor (UID en IMAP, id
 * en Graph/Gmail). El Message-ID RFC 5322 va aparte en MailMessage.
 *
 * Todas las operaciones lanzan MailProviderException (o una subclase) ante
 * cualquier error del proveedor; nunca excepciones de la librería.
 */
interface MailProvider
{
    /**
     * Comprueba recepción y envío por separado sin lanzar excepciones: el
     * resultado indica qué parte falla y por qué.
     */
    public function testConnection(): ConnectionTestResult;

    /**
     * @return list<MailFolder>
     *
     * @throws MailProviderException
     */
    public function folders(): array;

    /**
     * Últimos $limit mensajes de la carpeta, del más reciente al más antiguo.
     *
     * @return list<MailMessage>
     *
     * @throws MailProviderException
     */
    public function messages(string $folder, int $limit = 50): array;

    /**
     * @throws MailProviderException
     */
    public function message(string $folder, string $id): MailMessage;

    /**
     * @throws MailProviderException
     */
    public function downloadAttachment(string $folder, string $messageId, string $attachmentId): AttachmentContent;

    /**
     * Mensajes llegados desde $cursor (null = primera sincronización: no
     * devuelve el histórico, solo fija el punto de partida). El cursor es
     * opaco y específico de cada proveedor; quien llama lo guarda y lo
     * devuelve en la siguiente llamada.
     *
     * @throws MailProviderException
     */
    public function newMessages(string $folder, ?string $cursor): NewMessages;

    /**
     * @throws MailProviderException
     */
    public function send(OutgoingMessage $message): SentMessage;
}
