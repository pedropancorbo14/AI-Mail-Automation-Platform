<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Tools\TenantToolService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

/** Ver, activar o desactivar las herramientas de la IA de una empresa. */
#[Signature('ai:tools
    {tenant : Id de la empresa}
    {--enable=* : Herramientas a activar}
    {--disable=* : Herramientas a desactivar}')]
#[Description('Muestra y gestiona las herramientas de la IA activadas para una empresa')]
class ManageTenantTools extends Command
{
    public function handle(TenantToolService $tools): int
    {
        $tenant = Tenant::find($this->argument('tenant'));

        if ($tenant === null) {
            $this->error('No existe esa empresa.');

            return self::FAILURE;
        }

        try {
            foreach ($this->option('enable') as $name) {
                $tools->enable($tenant, $name);
            }

            foreach ($this->option('disable') as $name) {
                $tools->disable($tenant, $name);
            }
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Herramientas de «{$tenant->name}»:");
        $this->table(['Herramienta', 'Activada', 'Disponible', 'Origen', 'La IA la ve'], array_map(fn (array $row) => [
            $row['tool'],
            $row['enabled'] ? 'sí' : 'no',
            $row['available'] ? 'sí' : 'no (sin datos configurados)',
            $row['source'],
            $row['enabled'] && $row['available'] ? 'SÍ' : 'no',
        ], $tools->statusFor($tenant)));

        return self::SUCCESS;
    }
}
