<?php

namespace App\Mail;

use App\Mail\Data\ConnectionTestResult;
use App\Mail\Enums\MailConnectionStatus;
use App\Mail\Exceptions\MailProviderException;
use App\Models\MailConnection;
use Illuminate\Support\Facades\Log;

/**
 * Operaciones de negocio sobre conexiones de correo (lo que no es propio
 * de un proveedor concreto).
 */
class MailConnectionService
{
    public function __construct(
        private readonly MailProviderFactory $providers,
    ) {
    }

    /**
     * Prueba la conexión y guarda el resultado: status active/failing,
     * last_tested_at y last_error. Una conexión desactivada sigue
     * desactivada (solo se actualiza el resultado de la prueba).
     */
    public function test(MailConnection $connection): ConnectionTestResult
    {
        try {
            $result = $this->providers->make($connection)->testConnection();
        } catch (MailProviderException $e) {
            // Proveedor no disponible o error al construirlo.
            $result = new ConnectionTestResult(false, false, $e->getMessage(), $e->getMessage());
        }

        if ($connection->status !== MailConnectionStatus::Disabled) {
            $connection->status = $result->ok() ? MailConnectionStatus::Active : MailConnectionStatus::Failing;
        }

        $connection->last_tested_at = now();
        $connection->last_error = $result->error();
        $connection->save();

        // tenant_id va en el Context de la petición (middleware "tenant").
        Log::log($result->ok() ? 'info' : 'warning', 'Prueba de conexión de correo', [
            'mail_connection_id' => $connection->id,
            'provider' => $connection->provider->value,
            'incoming_ok' => $result->incomingOk,
            'outgoing_ok' => $result->outgoingOk,
            'error' => $result->error(),
        ]);

        return $result;
    }
}
