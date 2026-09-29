<?php

namespace App\Tenancy\Exceptions;

use RuntimeException;

/**
 * Se ha intentado leer o escribir datos de un tenant sin tenant en contexto.
 * Falla cerrado: mejor un error que devolver datos de todas las empresas.
 */
class MissingTenantException extends RuntimeException
{
    public function __construct(string $message = 'No hay ningún tenant en contexto.')
    {
        parent::__construct($message);
    }
}
