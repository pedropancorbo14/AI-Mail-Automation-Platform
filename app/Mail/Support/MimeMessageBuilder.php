<?php

namespace App\Mail\Support;

use App\Mail\Data\MailAddress;
use App\Mail\Data\OutgoingMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Construye el email MIME a enviar (Symfony Mime) a partir de nuestro
 * OutgoingMessage. Común a SMTP y a Gmail (que recibe el MIME crudo).
 */
class MimeMessageBuilder
{
    /**
     * @param  MailAddress  $from  La cuenta de la conexión: único remitente posible.
     */
    public static function build(OutgoingMessage $message, MailAddress $from): Email
    {
        $email = (new Email())
            ->from(self::address($from))
            ->to(...array_map(self::address(...), $message->to))
            ->cc(...array_map(self::address(...), $message->cc))
            ->bcc(...array_map(self::address(...), $message->bcc))
            ->subject($message->subject);

        if ($message->textBody !== null) {
            $email->text($message->textBody);
        }

        if ($message->htmlBody !== null) {
            $email->html($message->htmlBody);
        }

        foreach ($message->attachments as $attachment) {
            $email->attach($attachment->content, $attachment->filename, $attachment->contentType);
        }

        // Hilo: el cliente del destinatario agrupa la respuesta con la conversación.
        if ($message->inReplyTo !== null) {
            $email->getHeaders()->addIdHeader('In-Reply-To', $message->inReplyTo);
        }

        if ($message->references !== []) {
            $email->getHeaders()->addIdHeader('References', $message->references);
        }

        // RFC 3834: así otros sistemas automáticos (respuestas de fuera de la
        // oficina, tickets...) no contestan a nuestra respuesta automática.
        if ($message->autoSubmitted) {
            $email->getHeaders()->addTextHeader('Auto-Submitted', 'auto-replied');
        }

        return $email;
    }

    private static function address(MailAddress $address): Address
    {
        return new Address($address->email, $address->name ?? '');
    }
}
