<?php

namespace App\Mail\Enums;

enum MailEncryption: string
{
    /** TLS desde el inicio (IMAPS 993, SMTPS 465). */
    case Ssl = 'ssl';

    /** Conexión en claro que se eleva a TLS (IMAP 143, SMTP 587). */
    case StartTls = 'starttls';

    /** Sin cifrado: solo para servidores locales/de pruebas. */
    case None = 'none';
}
