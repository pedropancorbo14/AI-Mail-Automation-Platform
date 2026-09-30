<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\MailConnections\StoreMailConnectionRequest;
use App\Http\Requests\MailConnections\UpdateMailConnectionRequest;
use App\Http\Resources\MailConnectionResource;
use App\Mail\Enums\MailConnectionStatus;
use App\Mail\Enums\MailProviderType;
use App\Mail\MailConnectionService;
use App\Models\MailConnection;
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
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', MailConnection::class);

        return MailConnectionResource::collection(
            MailConnection::orderBy('name')->paginate(25),
        );
    }

    public function store(StoreMailConnectionRequest $request): MailConnectionResource
    {
        $connection = new MailConnection($request->safe()->only(['name', 'email']));
        $connection->provider = MailProviderType::Imap;
        $connection->credentials = $request->credentials();
        $connection->created_by = $request->user()->id;
        $connection->save();

        return new MailConnectionResource($connection->refresh());
    }

    public function show(MailConnection $mailConnection): MailConnectionResource
    {
        Gate::authorize('view', $mailConnection);

        return new MailConnectionResource($mailConnection);
    }

    public function update(UpdateMailConnectionRequest $request, MailConnection $mailConnection): MailConnectionResource
    {
        $mailConnection->fill($request->safe()->only(['name', 'email']));

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

        return new MailConnectionResource($mailConnection);
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

        $mailConnection->delete();

        return response()->noContent();
    }
}
