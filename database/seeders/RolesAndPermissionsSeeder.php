<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Guarda en BD el catálogo de roles y permisos definido en Role::CATALOG,
 * Permission::CATALOG y Role::PERMISSIONS. Idempotente: se puede ejecutar en
 * cada despliegue sin duplicar filas, y sincroniza los permisos de cada rol.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Role::CATALOG as $name => $label) {
            Role::updateOrCreate(['name' => $name], ['label' => $label]);
        }

        foreach (Permission::CATALOG as $name => $label) {
            Permission::updateOrCreate(['name' => $name], ['label' => $label]);
        }

        foreach (Role::PERMISSIONS as $role => $permissions) {
            Role::where('name', $role)->firstOrFail()->permissions()->sync(
                Permission::whereIn('name', $permissions)->pluck('id'),
            );
        }
    }
}
