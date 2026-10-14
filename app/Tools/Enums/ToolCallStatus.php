<?php

namespace App\Tools\Enums;

enum ToolCallStatus: string
{
    /** La herramienta se ejecutó y devolvió datos. */
    case Success = 'success';

    /** Se ejecutó pero falló (no encontrado, servicio caído...): ver error. */
    case Error = 'error';

    /** No se llegó a ejecutar: herramienta desconocida o desactivada, argumentos inválidos o sin permiso. */
    case Rejected = 'rejected';
}
