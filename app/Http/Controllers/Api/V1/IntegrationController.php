<?php

namespace App\Http\Controllers\Api\V1;

use App\ERP\Connections\IntegrationService;
use App\Http\Controllers\Controller;
use App\Http\Resources\TenantIntegrationResource;
use App\Models\Permission;
use App\Models\TenantIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Sistemas que conecta la empresa actual: `/integrations/standard-api` (su
 * ERP o programa propio) y `/integrations/woocommerce` (su tienda). Sin id
 * en la URL: siempre la conexión de la empresa del usuario. Las credenciales
 * nunca se devuelven.
 */
class IntegrationController extends Controller
{
    /** Segmento de la URL => tipo guardado. */
    public const array TYPES = [
        'standard-api' => TenantIntegration::STANDARD_API,
        'woocommerce' => TenantIntegration::WOOCOMMERCE,
    ];

    public function __construct(private readonly IntegrationService $connections)
    {
    }

    public function show(string $type): JsonResponse
    {
        Gate::authorize(Permission::ORDERS_VIEW);
        $connection = $this->connections->current(self::TYPES[$type]);

        return response()->json(['data' => $connection ? new TenantIntegrationResource($connection) : null]);
    }

    /** Crea o cambia la conexión y la prueba al momento. */
    public function update(Request $request, string $type): JsonResponse
    {
        Gate::authorize(Permission::ORDERS_MANAGE);
        $type = self::TYPES[$type];
        $creating = $this->connections->current($type) === null;
        $required = $creating ? 'required' : 'nullable';

        $rules = [
            'base_url' => ['required', 'string', 'max:2000', function (string $attribute, mixed $value, \Closure $fail) use ($type) {
                if ($reason = $this->connections->rejectUrl($type, (string) $value)) {
                    $fail($reason);
                }
            }],
            'enabled' => ['required', 'boolean'],
        ];
        $rules += $type === TenantIntegration::WOOCOMMERCE
            ? [
                'consumer_key' => [$required, 'required_with:consumer_secret', 'string', 'max:200'],
                'consumer_secret' => [$required, 'required_with:consumer_key', 'string', 'max:200'],
            ]
            : [
                'token' => [$required, 'string', 'max:2000'],
                'orders' => ['required', 'boolean'],
                'invoices' => ['required', 'boolean'],
                'appointments' => ['sometimes', 'boolean'],
            ];

        ['connection' => $connection, 'result' => $result] = $this->connections->save($type, $request->validate($rules), $request->user());

        return response()->json(['data' => new TenantIntegrationResource($connection), 'result' => $result->toArray()]);
    }

    public function test(string $type): JsonResponse
    {
        Gate::authorize(Permission::ORDERS_MANAGE);
        $connection = $this->connections->current(self::TYPES[$type]) ?? abort(404);

        ['result' => $result] = $this->connections->test($connection);

        return response()->json(['data' => new TenantIntegrationResource($connection->refresh()), 'result' => $result->toArray()]);
    }

    public function destroy(string $type): JsonResponse
    {
        Gate::authorize(Permission::ORDERS_MANAGE);
        $connection = $this->connections->current(self::TYPES[$type]) ?? abort(404);

        $this->connections->delete($connection);

        return response()->json(['data' => null]);
    }
}
