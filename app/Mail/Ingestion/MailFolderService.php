<?php

namespace App\Mail\Ingestion;

use App\Mail\Data\MailFolder;
use App\Mail\Exceptions\MailNotFoundException;
use App\Mail\MailProviderFactory;
use App\Models\MailConnection;
use App\Models\MailSyncState;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Qué carpetas de un buzón se sincronizan (una fila de mail_sync_states por
 * carpeta). La bandeja de entrada siempre; el resto, las que elija la empresa.
 *
 * Nunca se sincronizan Enviados, Borradores, Papelera ni Spam: en Enviados
 * están las respuestas de la propia empresa (la IA se contestaría a sí
 * misma) y en el resto hay correo que nadie quiere que se responda.
 */
class MailFolderService
{
    /** Roles que nunca se sincronizan. */
    public const array EXCLUDED_ROLES = [MailFolder::SENT, MailFolder::DRAFTS, MailFolder::TRASH, MailFolder::JUNK];

    public function __construct(private readonly MailProviderFactory $providers)
    {
    }

    /**
     * Carpetas del buzón (pidiéndoselas al proveedor) con su estado de sincronización.
     *
     * @return list<array{id: string, name: string, role: string|null, selectable: bool, synced: bool, last_success_at: string|null, last_error: string|null}>
     *
     * @throws \App\Mail\Exceptions\MailProviderException si el proveedor no responde
     */
    public function list(MailConnection $connection): array
    {
        $states = $connection->syncStates()->get()->keyBy('folder');

        return array_map(function (MailFolder $folder) use ($states) {
            /** @var MailSyncState|null $state */
            $state = $states->get($folder->id);

            return [
                'id' => $folder->id,
                'name' => $folder->name,
                'role' => $folder->role,
                // La bandeja de entrada no se puede quitar y las excluidas no se pueden poner.
                'selectable' => $folder->role !== MailFolder::INBOX && ! in_array($folder->role, self::EXCLUDED_ROLES, true),
                'synced' => $state !== null || $folder->role === MailFolder::INBOX,
                'last_success_at' => $state?->last_success_at?->toIso8601String(),
                'last_error' => $state?->last_error,
            ];
        }, $this->providers->make($connection)->folders());
    }

    /**
     * Fija las carpetas a sincronizar además de la bandeja de entrada. Una
     * carpeta nueva empieza sin histórico (como la bandeja la primera vez);
     * una que se quita pierde su cursor.
     *
     * @param  list<string>  $folderIds
     * @return array{added: list<string>, removed: list<string>} nombres de las carpetas
     *
     * @throws ValidationException carpeta inexistente, excluida o demasiadas
     */
    public function set(MailConnection $connection, array $folderIds): array
    {
        $folders = collect($this->providers->make($connection)->folders())->keyBy('id');
        $inbox = $folders->first(fn (MailFolder $f) => $f->role === MailFolder::INBOX)
            ?? throw new MailNotFoundException('No se ha encontrado la bandeja de entrada del buzón.');

        $wanted = collect($folderIds)->unique()->reject(fn (string $id) => $id === $inbox->id)->values();

        foreach ($wanted as $id) {
            $folder = $folders->get($id) ?? throw ValidationException::withMessages(['folders' => "La carpeta «{$id}» no existe en el buzón."]);

            if (in_array($folder->role, self::EXCLUDED_ROLES, true)) {
                throw ValidationException::withMessages(['folders' => "La carpeta «{$folder->name}» no se puede sincronizar (enviados, borradores, papelera y spam nunca se procesan)."]);
            }
        }

        $max = (int) config('mail_connections.sync_max_folders');

        if ($wanted->count() + 1 > $max) {
            throw ValidationException::withMessages(['folders' => "Como máximo se sincronizan {$max} carpetas por buzón (con la bandeja de entrada)."]);
        }

        $keep = $wanted->push($inbox->id)->all();
        $current = $connection->syncStates()->pluck('folder')->all();
        $added = array_values(array_diff($keep, $current));
        $removed = array_values(array_diff($current, $keep));

        DB::transaction(function () use ($connection, $added, $removed) {
            $connection->syncStates()->whereIn('folder', $removed)->delete();

            foreach ($added as $id) {
                $connection->syncStates()->create(['folder' => $id]);
            }
        });

        $name = fn (string $id) => $folders->get($id)?->name ?? $id;

        return ['added' => array_map($name, $added), 'removed' => array_map($name, $removed)];
    }
}
