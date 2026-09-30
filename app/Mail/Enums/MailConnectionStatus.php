<?php

namespace App\Mail\Enums;

enum MailConnectionStatus: string
{
    /** Creada, todavía sin probar. */
    case Pending = 'pending';

    /** Última prueba correcta. */
    case Active = 'active';

    /** Última prueba fallida (ver last_error). */
    case Failing = 'failing';

    /** Desactivada a mano: no se sincroniza ni se envía. */
    case Disabled = 'disabled';
}
