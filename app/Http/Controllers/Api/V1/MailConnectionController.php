<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\MailConnections\StoreMailConnectionRequest;
use App\Http\Requests\MailConnections\UpdateMailConnectionFoldersRequest;
use App\Http\Requests\MailConnections\UpdateMailConnectionRequest;
use App\Http\Resources\MailConnectionResource;
use App\Mail\Autodetect\MailAutodetector;
use App\Mail\Enums\MailConnectionStatus;
use App\Mail\Enums\MailProviderType;
use App\Mail\Exceptions\MailProviderException;
use App\Mail\Ingestion\MailFolderService;
use App\Mail\MailConnectionService;
use App\Models\MailConnection;
use App\Security\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Conexiones de correo de la empresa actual. BelongsToTenant filtra todas
 * las consultas y asigna tenant_id al crear; el route binding de
 * {mailConnection} ya devuelve 404 para conexiones de otra empresa.
 */
class MailConnectionController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', MailConnection::class);

        return MailConnectionResource::collection(
            MailConnection::orderBy('name')->withCount('skippedMessages')->paginate(25),
        );
    }

    public function store(StoreMailConnectionRequest $request): MailConnectionResource
    {
        $connection = new MailConnection($request->safe()->only(['name', 'email', 'save_sent_copy']));
        $connection->provider = MailProviderType::Imap;
        $connection->credentials = $request->credentials();
        $connection->created_by = $request->user()->id;
        $connection->save();

        $this->audit->record('mail_connection.created', $connection, $connection->name, metadata: [
            'email' => $connection->email,
            'provider' => $connection->provider->value,
        ]);

        return new MailConnectionResource($connection->refresh());
    }

    public function show(MailConnection $mailConnection): MailConnectionResource
    {
        Gate::authorize('view', $mailConnection);

        return new MailConnectionResource($mailConnection);
    }

    public function update(UpdateMailConnectionRequest $request, MailConnection $mailConnection): MailConnectionResource
    {
        $mailConnection->fill($request->safe()->only(['name', 'email', 'save_sent_copy']));

        if ($request->changesCredentials()) {
            $mailConnection->credentials = $request->credentials($mailConnection->credentials);
            // Credenciales nuevas: hay que volver a probarlas.
            $mailConnection->status = MailConnectionStatus::Pending;
            $mailConnection->last_error = null;
        }

        if ($status = $request->status()) {
            $mailConnection->status = $status;
        }

        $mailConnection->save();

        $changes = AuditLogger::changesOf($mailConnection, ['name', 'email', 'status', 'save_sent_copy']);

        if ($request->changesCredentials()) {
            // Solo consta que cambiaron (sale "[oculto]"), nunca su contenido.
            $changes['credentials'] = true;
        }

        if ($changes !== []) {
            $this->audit->record('mail_connection.updated', $mailConnection, $mailConnection->name, $changes);
        }

        return new MailConnectionResource($mailConnection);
    }

    /**
     * Carpetas del buzón y cuáles se sincronizan. Pregunta al proveedor
     * (throttle:mail-connection-test, como probar la conexión).
     */
    public function folders(MailConnection $mailConnection, MailFolderService $folders): JsonResponse
    {
        Gate::authorize('view', $mailConnection);

        try {
            return response()->json(['data' => $folders->list($mailConnection)]);
        } catch (MailProviderException $e) {
            return response()->json(['message' => 'No se pudieron leer las carpetas del buzón: ' . $e->getMessage()], 422);
        }
    }

    /** Elige las carpetas que se sincronizan además de la bandeja de entrada. */
    public function updateFolders(UpdateMailConnectionFoldersRequest $request, MailConnection $mailConnection, MailFolderService $folders): JsonResponse
    {
        try {
            $changes = $folders->set($mailConnection, $request->folders());
        } catch (MailProviderException $e) {
            return response()->json(['message' => 'No se pudieron leer las carpetas del buzón: ' . $e->getMessage()], 422);
        }

        if ($changes['added'] !== [] || $changes['removed'] !== []) {
            $this->audit->record('mail_connection.folders_changed', $mailConnection, $mailConnection->name, metadata: $changes);
        }

        return response()->json(['data' => $folders->list($mailConnection)]);
    }

    /**
     * Prueba recepción y envío con las credenciales guardadas y actualiza el
     * estado de la conexión. Limitado (throttle:mail-connection-test): cada
     * prueba abre conexiones hacia servidores externos.
     */
    public function test(MailConnection $mailConnection, MailConnectionService $service): JsonResponse
    {
        Gate::authorize('update', $mailConnection);

        $result = $service->test($mailConnection);
        $this->audit->record('mail_connection.tested', $mailConnection, $mailConnection->name, metadata: [
            'incoming_ok' => $result->incomingOk,
            'outgoing_ok' => $result->outgoingOk,
        ]);

        return response()->json([
            'data' => new MailConnectionResource($mailConnection),
            'result' => [
                'ok' => $result->ok(),
                'incoming_ok' => $result->incomingOk,
                'outgoing_ok' => $result->outgoingOk,
                'incoming_error' => $result->incomingError,
                'outgoing_error' => $result->outgoingError,
            ],
        ]);
    }

    public function destroy(Request $request, MailConnection $mailConnection): Response
    {
        Gate::authorize('delete', $mailConnection);

        // Los correos guardados dependen del buzón (restrictOnDelete): no se
        // pierden en silencio. Se puede desactivar para dejar de sincronizar.
        if ($mailConnection->emails()->exists()) {
            abort(409, 'La conexión tiene correos guardados: desactívala en lugar de borrarla.');
        }

        $this->audit->record('mail_connection.deleted', $mailConnection, $mailConnection->name, metadata: [
            'email' => $mailConnection->email,
            'provider' => $mailConnection->provider->value,
        ]);
        $mailConnection->delete();

        return response()->noContent();
    }

    /**
     * Propone la configuración IMAP/SMTP de una dirección (ISPDB de
     * Thunderbird, autoconfig del dominio, DNS SRV, nombres habituales) y
     * avisa si el dominio está en Microsoft 365 o Google. No guarda nada.
     */
    public function autodetect(Request $request, MailAutodetector $autodetector): JsonResponse
    {
        Gate::authorize('create', MailConnection::class);

        $email = $request->validate(['email' => ['required', 'email:rfc', 'max:255']])['email'];

        return response()->json(['data' => $autodetector->detect($email)->toArray()]);
    }
}
