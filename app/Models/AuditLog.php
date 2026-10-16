<?php

namespace App\Models;

use App\Tenancy\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Una acción registrada en la auditoría. Solo la crea App\Security\Audit\
 * AuditLogger y no se puede modificar ni borrar desde la aplicación (en la
 * BD, un trigger impide los UPDATE; solo la retención borra lo antiguo,
 * con un DELETE masivo que no pasa por el modelo).
 *
 * No usa BelongsToTenant para escribir: hay registros sin empresa (un login
 * fallido con un email desconocido). Pero LEER sí va filtrado por la
 * empresa en contexto (TenantScope, falla cerrado).
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        static::updating(fn () => throw new LogicException('El registro de auditoría no se puede modificar.'));
        static::deleting(fn () => throw new LogicException('El registro de auditoría no se puede borrar.'));
    }

    /** @return BelongsTo<User, $this> Quién actuó (null si el usuario ya no existe: queda actor_email). */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
