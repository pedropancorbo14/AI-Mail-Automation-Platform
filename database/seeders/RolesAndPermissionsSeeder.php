<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Catálogo de roles y permisos de la plataforma y qué permisos tiene cada
 * rol. Idempotente: se puede ejecutar en cada despliegue sin duplicar filas,
 * y sincroniza los permisos de cada rol con lo definido aquí.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public const array ROLES = [
        Role::OWNER => 'Propietario',
        Role::ADMIN => 'Administrador',
        Role::AGENT => 'Agente',
    ];

    public const array PERMISSIONS = [
        Permission::USERS_VIEW => 'Ver usuarios de la empresa',
        Permission::USERS_CREATE => 'Crear usuarios',
        Permission::USERS_UPDATE => 'Editar usuarios',
        Permission::USERS_DELETE => 'Eliminar usuarios',
        Permission::TENANT_VIEW => 'Ver datos de la empresa',
        Permission::TENANT_UPDATE => 'Editar datos de la empresa',
    ];

    public const array ROLE_PERMISSIONS = [
        Role::OWNER => [
            Permission::USERS_VIEW,
            Permission::USERS_CREATE,
            Permission::USERS_UPDATE,
            Permission::USERS_DELETE,
            Permission::TENANT_VIEW,
            Permission::TENANT_UPDATE,
        ],
        Role::ADMIN => [
            Permission::USERS_VIEW,
            Permission::USERS_CREATE,
            Permission::USERS_UPDATE,
            Permission::USERS_DELETE,
            Permission::TENANT_VIEW,
        ],
        Role::AGENT => [
            Permission::USERS_VIEW,
            Permission::TENANT_VIEW,
        ],
    ];

    public function run(): void
    {
        foreach (self::ROLES as $name => $label) {
            Role::updateOrCreate(['name' => $name], ['label' => $label]);
        }

        foreach (self::PERMISSIONS as $name => $label) {
            Permission::updateOrCreate(['name' => $name], ['label' => $label]);
        }

        foreach (self::ROLE_PERMISSIONS as $role => $permissions) {
            Role::where('name', $role)->firstOrFail()->permissions()->sync(
                Permission::whereIn('name', $permissions)->pluck('id'),
            );
        }
    }
}
