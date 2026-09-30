<?php

namespace App\Mail\Ingestion;

use App\Mail\Contracts\MailProvider;
use App\Mail\Data\MailFolder;
use App\Mail\Enums\MailConnectionStatus;
use App\Mail\Exceptions\MailAuthenticationException;
use App\Mail\Exceptions\MailNotFoundException;
use App\Mail\Exceptions\MailProviderException;
use App\Mail\MailProviderFactory;
use App\Models\MailConnection;
use App\Models\MailSyncState;
use App\Tenancy\TenantService;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sincroniza un buzón: pide al proveedor los correos nuevos de cada carpeta
 * (con el cursor guardado en mail_sync_states), los guarda y solo entonces
 * avanza el cursor. Si algo falla, el cursor no se mueve y el siguiente
 * intento repite el lote; la deduplicación evita guardar nada dos veces.
 *
 * De momento se sincroniza solo la bandeja de entrada.
 */
class MailSyncService
{
    public function __construct(
        private readonly MailProviderFactory $providers,
        private readonly EmailIngestionService $ingestion,
        private readonly TenantService $tenants,
    ) {
    }

    /**
     * @throws Throwable el error que haya impedido sincronizar (ya registrado en
     *                   mail_sync_states y en el log), para que el job decida si reintenta.
     */
    public function sync(MailConnection $connection): SyncResult
    {
        return $this->tenants->runAs($connection->tenant, function () use ($connection) {
            Context::add('tenant_id', $connection->tenant_id);

            if ($connection->status === MailConnectionStatus::Disabled) {
                return new SyncResult();
            }

            try {
                $provider = $this->providers->make($connection);
                $states = $this->states($connection, $provider);
            } catch (Throwable $e) {
                $this->failed($connection, null, $e);

                throw $e;
            }

            $result = new SyncResult();

            foreach ($states as $state) {
                $result = $result->merge($this->syncFolder($connection, $provider, $state));
            }

            return $result;
        });
    }

    private function syncFolder(MailConnection $connection, MailProvider $provider, MailSyncState $state): SyncResult
    {
        $result = new SyncResult();
        $batches = 0;

        try {
            do {
                $batch = $provider->newMessages($state->folder, $state->cursor);
                $stored = $duplicates = 0;

                foreach ($batch->messages as $message) {
                    $email = $this->ingestion->ingest($connection, $message);
                    $this->ingestion->complete($provider, $email);
                    $email->wasRecentlyCreated ? $stored++ : $duplicates++;
                }

                // Solo con todo el lote guardado se avanza el cursor.
                $state->recordSuccess($batch->cursor, $batch->resynced);
                $result = $result->merge(new SyncResult($stored, $duplicates, $batch->hasMore, $batch->resynced));

                if ($batch->resynced) {
                    Log::warning('El proveedor invalidó el cursor del buzón: puede faltar correo intermedio', $this->context($connection, $state));
                }
            } while ($batch->hasMore && ++$batches < config('mail_connections.sync_max_batches'));
        } catch (Throwable $e) {
            $this->failed($connection, $state, $e);

            throw $e;
        }

        Log::info('Buzón sincronizado', $this->context($connection, $state) + [
            'stored' => $result->stored,
            'duplicates' => $result->duplicates,
            'has_more' => $result->hasMore,
        ]);

        return $result;
    }

    /**
     * Estados de las carpetas a sincronizar. La primera vez se busca la
     * bandeja de entrada entre las carpetas del proveedor y se guarda su id.
     *
     * @return list<MailSyncState>
     */
    private function states(MailConnection $connection, MailProvider $provider): array
    {
        $states = $connection->syncStates()->orderBy('id')->get()->all();

        if ($states !== []) {
            return $states;
        }

        $inbox = collect($provider->folders())->first(fn (MailFolder $folder) => $folder->role === MailFolder::INBOX)
            ?? throw new MailNotFoundException('No se ha encontrado la bandeja de entrada del buzón.');

        return [$connection->syncStates()->create(['folder' => $inbox->id])];
    }

    /**
     * Registra el fallo: en la carpeta (last_error, consecutive_failures), en
     * la conexión si la cuenta rechaza las credenciales, y en el log.
     */
    private function failed(MailConnection $connection, ?MailSyncState $state, Throwable $e): void
    {
        // Los errores del proveedor tienen mensajes aptos para el usuario; el
        // resto (BD, bugs...) solo van al log con detalle.
        $message = $e instanceof MailProviderException ? $e->getMessage() : 'Error interno al sincronizar el buzón.';

        $state?->recordFailure($message);

        if ($e instanceof MailAuthenticationException && $connection->status !== MailConnectionStatus::Disabled) {
            $connection->forceFill(['status' => MailConnectionStatus::Failing, 'last_error' => $message])->save();
        }

        Log::error('Error al sincronizar el buzón', $this->context($connection, $state) + [
            'error' => $message,
            'exception' => $e::class,
        ] + ($e instanceof MailProviderException ? [] : ['detail' => $e->getMessage()]));
    }

    /** @return array<string, mixed> */
    private function context(MailConnection $connection, ?MailSyncState $state): array
    {
        return [
            'mail_connection_id' => $connection->id,
            'provider' => $connection->provider->value,
            'folder' => $state?->folder,
        ];
    }
}
