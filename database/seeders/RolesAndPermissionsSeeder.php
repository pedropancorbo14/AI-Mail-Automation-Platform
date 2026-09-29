<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Catálogo de roles de la plataforma. Idempotente: se puede ejecutar en
 * cada despliegue sin duplicar filas.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public const array ROLES = [
        Role::OWNER => 'Propietario',
        Role::ADMIN => 'Administrador',
        Role::AGENT => 'Agente',
    ];

    public function run(): void
    {
        foreach (self::ROLES as $name => $label) {
            Role::updateOrCreate(['name' => $name], ['label' => $label]);
        }
    }
}
