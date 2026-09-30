<?php

namespace App\Mail\Exceptions;

use RuntimeException;

/**
 * Error de un proveedor de correo. Los proveedores traducen cualquier
 * excepción de su librería/API a esta (o a una subclase) con un mensaje
 * apto para el usuario y sin credenciales.
 */
class MailProviderException extends RuntimeException
{
}
