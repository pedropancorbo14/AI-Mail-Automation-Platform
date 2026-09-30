<?php

namespace App\Mail\Providers\Google;

use App\Mail\Contracts\MailProvider;
use App\Mail\Data\AttachmentContent;
use App\Mail\Data\ConnectionTestResult;
use App\Mail\Data\MailMessage;
use App\Mail\Data\NewMessages;
use App\Mail\Data\OutgoingMessage;
use App\Mail\Data\SentMessage;
use App\Mail\Exceptions\MailProviderException;

/**
 * Cuentas de Gmail / Google Workspace a través de la Gmail API (OAuth),
 * con permisos acotados: gmail.readonly (leer) y gmail.send (enviar).
 */
class GmailProvider implements MailProvider
{
    public const string SCOPE_READ = 'https://www.googleapis.com/auth/gmail.readonly';

    public const string SCOPE_SEND = 'https://www.googleapis.com/auth/gmail.send';

    /**
     * @param  list<string>  $scopes  Permisos concedidos al token (de OAuthCredentials).
     */
    public function __construct(
        private readonly GmailClient $gmail,
        private readonly array $scopes = [],
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

    public function folders(): array
    {
        throw new MailProviderException('Lectura de etiquetas de Gmail todavía no implementada.');
    }

    public function messages(string $folder, int $limit = 50): array
    {
        throw new MailProviderException('Lectura de mensajes de Gmail todavía no implementada.');
    }

    public function message(string $folder, string $id): MailMessage
    {
        throw new MailProviderException('Lectura de mensajes de Gmail todavía no implementada.');
    }

    public function downloadAttachment(string $folder, string $messageId, string $attachmentId): AttachmentContent
    {
        throw new MailProviderException('Descarga de adjuntos de Gmail todavía no implementada.');
    }

    public function newMessages(string $folder, ?string $cursor): NewMessages
    {
        throw new MailProviderException('Detección de nuevos emails de Gmail todavía no implementada.');
    }

    public function send(OutgoingMessage $message): SentMessage
    {
        throw new MailProviderException('Envío por Gmail todavía no implementado.');
    }
}
