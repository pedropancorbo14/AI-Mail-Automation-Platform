<?php

namespace App\Mail\Ingestion;

use App\Mail\Enums\MailConnectionStatus;
use App\Mail\Exceptions\MailAuthenticationException;
use App\Models\MailConnection;
use App\Tenancy\Scopes\TenantScope;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Trae los correos nuevos de un buzón (MailSyncService) en la cola "mail".
 *
 * Recibe el id y no el modelo: al deserializar no hay tenant en contexto y
 * BelongsToTenant fallaría; el servicio ya se ejecuta dentro del tenant.
 * Único por buzón mientras está en cola, ejecutándose o esperando
 * reintento: el scheduler no apila sincronizaciones del mismo buzón.
 */
class FetchEmailsJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    /** Intentos ante errores temporales (red, timeouts, 5xx del proveedor). */
    public int $tries = 3;

    /** Por debajo de retry_after (300 s) de la cola redis. */
    public int $timeout = 240;

    /** Segundos que dura el bloqueo de unicidad si el worker muere sin liberarlo. */
    public int $uniqueFor = 900;

    public function __construct(public readonly int $mailConnectionId)
    {
        $this->onQueue('mail');
    }

    public function uniqueId(): string
    {
        return (string) $this->mailConnectionId;
    }

    /** Espera entre intentos (segundos). */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(MailSyncService $sync): void
    {
        // Búsqueda global deliberada: aún no hay tenant; sync() entra en el de la conexión.
        $connection = MailConnection::withoutGlobalScope(TenantScope::class)
            ->with('tenant')
            ->find($this->mailConnectionId);

        // Borrada, desactivada o de una empresa desactivada mientras esperaba en cola.
        if ($connection === null
            || $connection->status === MailConnectionStatus::Disabled
            || ! $connection->tenant->is_active) {
            return;
        }

        try {
            $sync->sync($connection);
        } catch (MailAuthenticationException $e) {
            // Credenciales rechazadas: reintentar no lo arregla y repetir logins
            // fallidos puede bloquear la cuenta. Ya está marcada como failing.
            $this->fail($e);
        }
        // Cualquier otro error se relanza: la cola reintenta con backoff().
    }

    /** Agotados los intentos (o fallo definitivo): el detalle de cada intento ya está en el log. */
    public function failed(?Throwable $e): void
    {
        Log::warning('Sincronización de buzón abandonada', [
            'mail_connection_id' => $this->mailConnectionId,
            'exception' => $e ? $e::class : null,
        ]);
    }
}
