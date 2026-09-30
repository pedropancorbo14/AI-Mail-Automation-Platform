<?php

namespace App\Mail\Ingestion\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Email guardado por completo (con adjuntos): punto de entrada del
 * procesamiento con IA (Fase 9). Lleva ids y no el modelo: un listener en
 * cola lo recarga dentro de su tenant (TenantService::runAs), porque
 * BelongsToTenant falla sin tenant en contexto.
 */
final class EmailIngested
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $emailId,
    ) {
    }
}
