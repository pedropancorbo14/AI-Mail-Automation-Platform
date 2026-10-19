<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\CustomerOrder;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Tenancy\TenantService;
use Illuminate\Http\JsonResponse;

/**
 * GET /internal/integrations/{tenant}: qué sistemas tiene conectados una
 * empresa, para que el servidor MCP elija el proveedor de cada herramienta.
 * `null` = no tiene ese sistema (el servidor MCP usa entonces los datos de
 * demostración si la empresa los tiene, o nada).
 *
 * - `standard_api`: su propio sistema (URL y clave, descifrada: esta ruta solo
 *   la lee el servidor MCP con el token de datos). Manda sobre el CSV.
 * - `csv`: pedidos importados, servidos por /internal/standard-api/{tenant}.
 *
 * Una empresa desactivada no tiene nada. Nunca se guarda en cachés.
 */
class IntegrationsController extends Controller
{
    public function __invoke(int $tenant, TenantService $tenants): JsonResponse
    {
        $model = Tenant::find($tenant);
        $config = ['orders' => null, 'invoices' => null];

        if ($model !== null && $model->is_active) {
            $config = $tenants->runAs($model, function () {
                $api = TenantIntegration::where('type', TenantIntegration::STANDARD_API)->where('enabled', true)->first();
                $own = $api === null ? null : [
                    'type' => TenantIntegration::STANDARD_API,
                    'base_url' => $api->base_url,
                    'token' => $api->credentials,
                ];

                return [
                    'orders' => $api?->orders ? $own : (CustomerOrder::query()->exists() ? ['type' => 'csv'] : null),
                    'invoices' => $api?->invoices ? $own : null,
                ];
            });
        }

        return response()->json(['tenant_id' => $tenant, ...$config, 'tickets' => null])
            ->header('Cache-Control', 'no-store');
    }
}
