<?php

namespace App\Console\Commands;

use App\Mail\Enums\MailConnectionStatus;
use App\Mail\Ingestion\FetchEmailsJob;
use App\Models\MailConnection;
use App\Tenancy\Scopes\TenantScope;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Encola un FetchEmailsJob por cada buzón a sincronizar. Lo ejecuta el
 * scheduler cada minuto (routes/console.php); FetchEmailsJob es único por
 * buzón, así que no se acumulan si una sincronización tarda.
 *
 * Solo buzones "active" (verificados) de empresas activas: "pending" aún no
 * se ha probado y "failing" tiene las credenciales mal (reintentarlo cada
 * minuto podría bloquear la cuenta); vuelven a "active" al probarlos de nuevo.
 */
#[Signature('mail:sync {connection? : Id de una conexión concreta (por defecto, todas las activas)}')]
#[Description('Encola la sincronización de los buzones de correo activos')]
class SyncMailboxes extends Command
{
    public function handle(): int
    {
        $dispatched = 0;

        // Consulta global deliberada: recorre los buzones de todas las empresas.
        MailConnection::withoutGlobalScope(TenantScope::class)
            ->where('status', MailConnectionStatus::Active)
            ->whereHas('tenant', fn ($tenants) => $tenants->where('is_active', true))
            ->when($this->argument('connection'), fn ($query, $id) => $query->whereKey($id))
            ->select('id')
            ->chunkById(500, function ($connections) use (&$dispatched) {
                foreach ($connections as $connection) {
                    FetchEmailsJob::dispatch($connection->id);
                    $dispatched++;
                }
            });

        $this->info("Buzones encolados para sincronizar: {$dispatched}");

        return self::SUCCESS;
    }
}
