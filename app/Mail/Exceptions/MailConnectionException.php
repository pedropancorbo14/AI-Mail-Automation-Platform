<?php

namespace App\Mail\Exceptions;

/** No se ha podido conectar con el servidor (DNS, puerto, TLS, timeout...). */
class MailConnectionException extends MailProviderException
{
}
