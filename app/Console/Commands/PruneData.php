<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Security\Retention\RetentionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Aplica la retención: emails antiguos de cada empresa y auditoría antigua. Cada noche (routes/console.php). */
#[Signature('data:prune')]
#[Description('Borra los emails y la auditoría que superan su periodo de retención')]
class PruneData extends Command
{
    public function handle(RetentionService $retention): int
    {
        $rows = [];

        // Consulta global deliberada: la retención es de la plataforma.
        Tenant::query()->orderBy('id')->each(function (Tenant $tenant) use ($retention, &$rows) {
            $days = RetentionService::emailDays($tenant);
            $rows[] = [$tenant->name, $days > 0 ? "{$days} días" : 'sin límite', $retention->pruneEmails($tenant)];
        });

        $this->table(['Empresa', 'Retención de emails', 'Emails borrados'], $rows);
        $this->info('Registros de auditoría borrados: ' . $retention->pruneAuditLogs());

        return self::SUCCESS;
    }
}
