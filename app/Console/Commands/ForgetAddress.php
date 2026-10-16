<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Security\Retention\RetentionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Derecho de supresión (RGPD): borra todos los emails de una persona en una
 * empresa, como remitente o destinataria. Sin --force solo dice cuántos hay.
 */
#[Signature('data:forget-address
    {tenant : Id de la empresa}
    {address : Dirección de email de la persona}
    {--force : Borrar de verdad (sin esto, solo cuenta)}')]
#[Description('Borra todos los emails de una dirección en una empresa (derecho de supresión)')]
class ForgetAddress extends Command
{
    public function handle(RetentionService $retention): int
    {
        $tenant = Tenant::find($this->argument('tenant'));

        if ($tenant === null) {
            $this->error('No existe esa empresa.');

            return self::FAILURE;
        }

        $address = (string) $this->argument('address');

        if (! $this->option('force')) {
            $count = $retention->forgetAddress($tenant, $address, dryRun: true);
            $this->info("{$count} emails de {$address} en «{$tenant->name}». Repite con --force para borrarlos.");

            return self::SUCCESS;
        }

        $count = $retention->forgetAddress($tenant, $address);
        $this->info("Borrados {$count} emails de {$address} en «{$tenant->name}» (adjuntos y contenido de la IA incluidos).");

        return self::SUCCESS;
    }
}
