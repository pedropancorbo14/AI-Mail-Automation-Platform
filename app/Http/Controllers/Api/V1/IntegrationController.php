<?php

namespace App\Http\Controllers\Api\V1;

use App\ERP\StandardApi\StandardApiConnectionService;
use App\ERP\StandardApi\StandardApiTester;
use App\Http\Controllers\Controller;
use App\Http\Resources\TenantIntegrationResource;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Conexión de la empresa actual con su propio sistema por la API estándar
 * (contracts/integrations/). Sin id en la URL: siempre la de la empresa del
 * usuario. La clave nunca se devuelve.
 */
class IntegrationController extends Controller
{
    public function __construct(private readonly StandardApiConnectionService $connections)
    {
    }

    public function show(): JsonResponse
    {
        Gate::authorize(Permission::ORDERS_VIEW);
        $connection = $this->connections->current();

        return response()->json(['data' => $connection ? new TenantIntegrationResource($connection) : null]);
    }

    /** Crea o cambia la conexión y la prueba al momento. */
    public function update(Request $request, StandardApiTester $tester): JsonResponse
    {
        Gate::authorize(Permission::ORDERS_MANAGE);
        $creating = $this->connections->current() === null;

        $data = $request->validate([
            'base_url' => ['required', 'string', 'max:2000', function (string $attribute, mixed $value, \Closure $fail) use ($tester) {
                if ($reason = $tester->rejectUrl((string) $value)) {
                    $fail($reason);
                }
            }],
            'token' => [$creating ? 'required' : 'nullable', 'string', 'max:2000'],
            'orders' => ['required', 'boolean'],
            'invoices' => ['required', 'boolean'],
            'enabled' => ['required', 'boolean'],
        ]);

        ['connection' => $connection, 'result' => $result] = $this->connections->save($data, $request->user());

        return response()->json(['data' => new TenantIntegrationResource($connection), 'result' => $result->toArray()]);
    }

    public function test(): JsonResponse
    {
        Gate::authorize(Permission::ORDERS_MANAGE);
        $connection = $this->connections->current() ?? abort(404);

        ['result' => $result] = $this->connections->test($connection);

        return response()->json(['data' => new TenantIntegrationResource($connection->refresh()), 'result' => $result->toArray()]);
    }

    public function destroy(): JsonResponse
    {
        Gate::authorize(Permission::ORDERS_MANAGE);
        $connection = $this->connections->current() ?? abort(404);

        $this->connections->delete($connection);

        return response()->json(['data' => null]);
    }
}
