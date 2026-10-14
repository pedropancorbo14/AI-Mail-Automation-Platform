<?php

namespace App\Tools;

use App\Models\Email;
use App\Models\Tenant;

/**
 * Contexto en el que se ejecuta una herramienta. Lo construye el backend,
 * NUNCA la IA: las herramientas usan estos datos (empresa, remitente) para
 * acotar lo que devuelven, y no los argumentos que el modelo podría inventar.
 */
final readonly class ToolContext
{
    public function __construct(
        public Tenant $tenant,
        /** Email que se está procesando, si lo hay. */
        public ?Email $email = null,
        /** Petición de IA que pidió la herramienta (para enlazar el registro). */
        public ?int $aiRequestId = null,
    ) {
    }

    /** Remitente del email (en minúsculas): de quién son los datos que se pueden consultar. */
    public function senderEmail(): ?string
    {
        $from = $this->email?->from_email;

        return $from ? mb_strtolower(trim($from)) : null;
    }
}
