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

    public const string MAIL_CONNECTIONS_VIEW = 'mail_connections.view';

    /** Crear, editar, probar y eliminar conexiones de correo. */
    public const string MAIL_CONNECTIONS_MANAGE = 'mail_connections.manage';

    /** Ver la documentación de la empresa y buscar en ella. */
    public const string DOCUMENTS_VIEW = 'documents.view';

    /** Subir, reindexar y eliminar documentos. */
    public const string DOCUMENTS_MANAGE = 'documents.manage';

    /** Ver los emails recibidos, su clasificación, la respuesta propuesta y lo que hizo la IA. */
    public const string EMAILS_VIEW = 'emails.view';

    /** Cambiar el modo de automatización, las intenciones que se responden solas y las herramientas de la IA. */
    public const string AUTOMATION_MANAGE = 'automation.manage';

    /** Ver el registro de auditoría: quién hizo qué en la empresa. */
    public const string AUDIT_VIEW = 'audit.view';

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
        self::MAIL_CONNECTIONS_VIEW => 'Ver conexiones de correo',
        self::MAIL_CONNECTIONS_MANAGE => 'Gestionar conexiones de correo',
        self::DOCUMENTS_VIEW => 'Ver y buscar en la documentación',
        self::DOCUMENTS_MANAGE => 'Gestionar la documentación',
        self::EMAILS_VIEW => 'Ver los emails y lo que hizo la IA con ellos',
        self::AUTOMATION_MANAGE => 'Configurar la automatización y las herramientas de la IA',
        self::AUDIT_VIEW => 'Ver el registro de auditoría',
    ];

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
