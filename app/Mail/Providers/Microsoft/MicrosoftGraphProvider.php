<?php

namespace App\Mail\Providers\Microsoft;

use App\Mail\Contracts\MailProvider;
use App\Mail\Data\AttachmentContent;
use App\Mail\Data\ConnectionTestResult;
use App\Mail\Data\MailMessage;
use App\Mail\Data\NewMessages;
use App\Mail\Data\OutgoingMessage;
use App\Mail\Data\SentMessage;
use App\Mail\Exceptions\MailProviderException;

/**
 * Cuentas de Microsoft 365 / Outlook.com a través de Microsoft Graph
 * (OAuth). No usa IMAP/SMTP: Microsoft los está retirando para cuentas de
 * empresa y Graph permite permisos acotados (Mail.ReadWrite, Mail.Send).
 */
class MicrosoftGraphProvider implements MailProvider
{
    /**
     * @param  list<string>  $scopes  Permisos concedidos al token (de OAuthCredentials).
     */
    public function __construct(
        private readonly GraphClient $graph,
        private readonly array $scopes = [],
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

    public function folders(): array
    {
        throw new MailProviderException('Lectura de carpetas de Microsoft todavía no implementada.');
    }

    public function messages(string $folder, int $limit = 50): array
    {
        throw new MailProviderException('Lectura de mensajes de Microsoft todavía no implementada.');
    }

    public function message(string $folder, string $id): MailMessage
    {
        throw new MailProviderException('Lectura de mensajes de Microsoft todavía no implementada.');
    }

    public function downloadAttachment(string $folder, string $messageId, string $attachmentId): AttachmentContent
    {
        throw new MailProviderException('Descarga de adjuntos de Microsoft todavía no implementada.');
    }

    public function newMessages(string $folder, ?string $cursor): NewMessages
    {
        throw new MailProviderException('Detección de nuevos emails de Microsoft todavía no implementada.');
    }

    public function send(OutgoingMessage $message): SentMessage
    {
        throw new MailProviderException('Envío por Microsoft todavía no implementado.');
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
