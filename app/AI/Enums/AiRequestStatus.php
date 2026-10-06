<?php

namespace App\AI\Enums;

enum AiRequestStatus: string
{
    /** Respuesta válida. */
    case Success = 'success';

    /** Error del proveedor, de red, de configuración o respuesta inválida (ver error). */
    case Failed = 'failed';

    /** El modelo (y sus fallbacks) rechazó la petición por seguridad. */
    case Refused = 'refused';
}
