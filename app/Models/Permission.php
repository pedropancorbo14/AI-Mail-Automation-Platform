<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'label'])]
class Permission extends Model
{
    public const string USERS_VIEW = 'users.view';

    public const string USERS_CREATE = 'users.create';

    public const string USERS_UPDATE = 'users.update';

    public const string USERS_DELETE = 'users.delete';

    public const string TENANT_VIEW = 'tenant.view';

    public const string TENANT_UPDATE = 'tenant.update';

    /**
     * Catálogo completo (nombre => descripción). Cada permiso se registra
     * como Gate en AppServiceProvider y se guarda en BD con el seeder.
     */
    public const array CATALOG = [
        self::USERS_VIEW => 'Ver usuarios de la empresa',
        self::USERS_CREATE => 'Crear usuarios',
        self::USERS_UPDATE => 'Editar usuarios',
        self::USERS_DELETE => 'Eliminar usuarios',
        self::TENANT_VIEW => 'Ver datos de la empresa',
        self::TENANT_UPDATE => 'Editar datos de la empresa',
    ];

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
