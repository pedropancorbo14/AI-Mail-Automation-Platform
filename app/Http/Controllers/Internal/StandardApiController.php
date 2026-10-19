<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\CustomerOrder;
use App\Models\Tenant;
use App\Tenancy\TenantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * La API estándar (contracts/integrations/) sobre los pedidos que una empresa
 * importó por CSV: /internal/standard-api/{tenant}/v1/orders[/{number}].
 * La consulta el servidor MCP con el token de datos. Mismas reglas que exige
 * la especificación a cualquier empresa: solo pedidos de `customer_email`, y
 * un pedido de otro cliente responde 404 igual que uno que no existe.
 */
class StandardApiController extends Controller
{
    public function __construct(private readonly TenantService $tenants)
    {
    }

    public function order(Request $request, int $tenant, string $number): JsonResponse
    {
        return $this->forTenant($request, $tenant, ['customer_email' => 'required|string|max:254'], function (string $email) use ($number) {
            $order = CustomerOrder::where('number', mb_strtoupper($number))
                ->where('customer_email', $email)
                ->first();

            return $order === null
                ? response()->json(['message' => 'Recurso no encontrado.'], 404)
                : response()->json($order->toStandardApi());
        });
    }

    public function orders(Request $request, int $tenant): JsonResponse
    {
        $rules = ['customer_email' => 'required|string|max:254', 'limit' => 'required|integer|between:1,100'];

        return $this->forTenant($request, $tenant, $rules, fn (string $email) => response()->json([
            'data' => CustomerOrder::where('customer_email', $email)
                ->orderByRaw('placed_at desc nulls last')
                ->orderByDesc('id')
                ->limit((int) $request->query('limit'))
                ->get()
                ->map->toStandardApi()
                ->all(),
        ]));
    }

    /** @param callable(string): JsonResponse $respond */
    private function forTenant(Request $request, int $tenant, array $rules, callable $respond): JsonResponse
    {
        $model = Tenant::find($tenant);
        if ($model === null || ! $model->is_active) {
            return response()->json(['message' => 'Recurso no encontrado.'], 404);
        }

        $validator = Validator::make($request->query(), $rules);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 400);
        }

        $email = mb_strtolower(trim((string) $request->query('customer_email')));

        return $this->tenants->runAs($model, fn () => $respond($email));
    }
}
