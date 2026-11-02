<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'label'])]
class Role extends Model
{
    public const string OWNER = 'owner';

    public const string ADMIN = 'admin';

    public const string AGENT = 'agent';

    /** Catálogo de roles (nombre => descripción). */
    public const array CATALOG = [
        self::OWNER => 'Propietario',
        self::ADMIN => 'Administrador',
        self::AGENT => 'Agente',
    ];

    /** Permisos de cada rol. RolesAndPermissionsSeeder los sincroniza en BD. */
    public const array PERMISSIONS = [
        self::OWNER => [
            Permission::USERS_VIEW,
            Permission::USERS_CREATE,
            Permission::USERS_UPDATE,
            Permission::USERS_DELETE,
            Permission::TENANT_VIEW,
            Permission::TENANT_UPDATE,
            Permission::MAIL_CONNECTIONS_VIEW,
            Permission::MAIL_CONNECTIONS_MANAGE,
            Permission::DOCUMENTS_VIEW,
            Permission::DOCUMENTS_MANAGE,
            Permission::EMAILS_VIEW,
            Permission::AUTOMATION_MANAGE,
            Permission::AUDIT_VIEW,
            Permission::EMAILS_DELETE,
            Permission::EMAILS_REVIEW,
            Permission::ORDERS_VIEW,
            Permission::ORDERS_MANAGE,
            Permission::INTEGRATIONS_VIEW,
            Permission::INTEGRATIONS_MANAGE,
            // Solo el propietario contrata y paga.
            Permission::BILLING_MANAGE,
        ],
        self::ADMIN => [
            Permission::USERS_VIEW,
            Permission::USERS_CREATE,
            Permission::USERS_UPDATE,
            Permission::USERS_DELETE,
            Permission::TENANT_VIEW,
            Permission::MAIL_CONNECTIONS_VIEW,
            Permission::MAIL_CONNECTIONS_MANAGE,
            Permission::DOCUMENTS_VIEW,
            Permission::DOCUMENTS_MANAGE,
            Permission::EMAILS_VIEW,
            Permission::AUTOMATION_MANAGE,
            Permission::AUDIT_VIEW,
            Permission::EMAILS_DELETE,
            Permission::EMAILS_REVIEW,
            Permission::ORDERS_VIEW,
            Permission::ORDERS_MANAGE,
            Permission::INTEGRATIONS_VIEW,
            Permission::INTEGRATIONS_MANAGE,
        ],
        self::AGENT => [
            Permission::USERS_VIEW,
            Permission::TENANT_VIEW,
            Permission::MAIL_CONNECTIONS_VIEW,
            Permission::DOCUMENTS_VIEW,
            Permission::EMAILS_VIEW,
            // El trabajo diario de un agente de atención al cliente.
            Permission::EMAILS_REVIEW,
            // Consultar el pedido del cliente que escribe.
            Permission::ORDERS_VIEW,
            // Saber de qué sistemas saca datos la IA (sin poder cambiarlos).
            Permission::INTEGRATIONS_VIEW,
        ],
    ];

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }
}
