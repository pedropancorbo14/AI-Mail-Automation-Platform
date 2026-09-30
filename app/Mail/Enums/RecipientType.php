<?php

namespace App\Mail\Enums;

enum RecipientType: string
{
    case To = 'to';
    case Cc = 'cc';
    /** Solo se conoce en correos enviados desde el propio buzón. */
    case Bcc = 'bcc';
    /** Dirección a la que el remitente pide que se responda. */
    case ReplyTo = 'reply_to';
}
