<?php

namespace App\Documents\Exceptions;

use RuntimeException;

/**
 * El documento no se puede procesar (formato no soportado, sin texto,
 * fichero dañado...). Mensaje apto para el usuario; reintentar no lo arregla.
 */
class DocumentProcessingException extends RuntimeException
{
}
