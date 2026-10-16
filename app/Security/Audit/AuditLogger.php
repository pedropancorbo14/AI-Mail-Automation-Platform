<?php

namespace App\Security\Audit;

use App\Models\AuditLog;
use App\Models\User;
use App\Tenancy\TenantService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * Registra en audit_logs lo que hace una persona: quién (usuario y copia de
 * su email), en qué empresa, sobre qué (tipo, id y un nombre legible), qué
 * cambió, y desde dónde (IP, navegador y request_id de la petición).
 *
 * Nunca guarda secretos: cualquier campo que parezca una contraseña, un
 * token o unas credenciales se sustituye por "[oculto]" (también dentro de
 * arrays anidados).
 */
class AuditLogger
{
    public const string HIDDEN = '[oculto]';

    /** Partes de nombre de campo que delatan un secreto. */
    private const array SECRET_KEYS = ['password', 'secret', 'token', 'credential', 'api_key', 'apikey', 'authorization', 'cookie'];

    public function __construct(private readonly TenantService $tenants)
    {
    }

    /**
     * @param  string  $action  Qué pasó, en forma "objeto.verbo": "user.created", "auth.login_failed"...
     * @param  array<string, array{from: mixed, to: mixed}>  $changes  Campos cambiados.
     * @param  array<string, mixed>  $metadata  Datos extra (sin secretos: se ocultan igual).
     * @param  User|null  $actor  Quién actuó; por defecto, el usuario autenticado.
     * @param  int|null  $tenantId  Empresa; por defecto, la del actor o la del contexto.
     */
    public function record(
        string $action,
        ?Model $subject = null,
        ?string $subjectLabel = null,
        array $changes = [],
        array $metadata = [],
        ?User $actor = null,
        ?int $tenantId = null,
    ): AuditLog {
        $actor ??= Auth::user();
        $request = request();
        // Solo una petición HTTP real (con ruta) aporta IP y navegador; un comando o un job, no.
        $fromHttp = $request->route() !== null;

        $log = new AuditLog();
        $log->forceFill([
            'tenant_id' => $tenantId ?? $actor?->tenant_id ?? $this->tenants->id(),
            'user_id' => $actor?->id,
            'actor_email' => $actor?->email,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'subject_label' => $subjectLabel === null ? null : Str::limit($subjectLabel, 250),
            'changes' => $changes === [] ? null : self::hideSecrets($changes),
            'metadata' => $metadata === [] ? null : self::hideSecrets($metadata),
            'ip' => $fromHttp ? $request->ip() : null,
            'user_agent' => $fromHttp ? Str::limit((string) $request->userAgent(), 490) : null,
            'request_id' => Context::get('request_id'),
        ])->save();

        return $log;
    }

    /**
     * Cambios del último save() de un modelo, solo de los campos indicados:
     * {"campo": {"from": antes, "to": después}}, los dos con sus casts.
     *
     * @param  list<string>  $fields
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public static function changesOf(Model $model, array $fields): array
    {
        $changes = [];
        // Tras save() el original ya es el valor nuevo: lo anterior está en getPrevious() (en crudo).
        $before = (clone $model)->setRawAttributes([...$model->getAttributes(), ...$model->getPrevious()]);

        foreach (array_intersect(array_keys($model->getChanges()), $fields) as $field) {
            $changes[$field] = ['from' => self::plain($before->getAttribute($field)), 'to' => self::plain($model->getAttribute($field))];
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function hideSecrets(array $data): array
    {
        foreach ($data as $key => $value) {
            $name = strtolower((string) $key);

            if (collect(self::SECRET_KEYS)->contains(fn (string $secret) => str_contains($name, $secret))) {
                $data[$key] = self::HIDDEN;
            } elseif (is_array($value)) {
                $data[$key] = self::hideSecrets($value);
            }
        }

        return $data;
    }

    /** Enums y fechas como texto, para guardarlos en JSON. */
    private static function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
            default => $value,
        };
    }
}
