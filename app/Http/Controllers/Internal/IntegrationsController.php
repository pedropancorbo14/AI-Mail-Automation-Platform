<?php

namespace App\Http\Controllers\Internal;

use App\ERP\Connections\IntegrationService;
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
 *   la lee el servidor MCP con el token de datos). Manda sobre lo demás. Es
 *   el único origen de facturas y citas.
 * - `woocommerce`: su tienda (URL y claves de API, descifradas). Solo pedidos.
 * - `csv`: pedidos importados, servidos por /internal/standard-api/{tenant}.
 *
 * `tenant_slug`: con él el servidor MCP encuentra los datos de demostración
 * de la empresa ("empresa-demo", "clinica-demo") sin configurar ids.
 *
 * Una empresa desactivada no tiene nada. Nunca se guarda en cachés.
 */
class IntegrationsController extends Controller
{
    public function __invoke(int $tenant, TenantService $tenants): JsonResponse
    {
        $model = Tenant::find($tenant);
        $config = ['orders' => null, 'invoices' => null, 'appointments' => null];

        if ($model !== null && $model->is_active) {
            $config = $tenants->runAs($model, function () {
                $enabled = TenantIntegration::where('enabled', true)->get()->keyBy('type');
                $api = $enabled->get(TenantIntegration::STANDARD_API);
                $shop = $enabled->get(TenantIntegration::WOOCOMMERCE);
                $own = $api === null ? null : [
                    'type' => TenantIntegration::STANDARD_API,
                    'base_url' => $api->base_url,
                    'token' => $api->credentials,
                ];

                // Pedidos: su ERP, si no su tienda, si no lo importado por CSV.
                $orders = match (true) {
                    (bool) $api?->orders => $own,
                    $shop !== null => ['type' => TenantIntegration::WOOCOMMERCE, 'base_url' => $shop->base_url, ...IntegrationService::wooKeys($shop)],
                    CustomerOrder::query()->exists() => ['type' => 'csv'],
                    default => null,
                };

                return [
                    'orders' => $orders,
                    'invoices' => $api?->invoices ? $own : null,
                    'appointments' => $api?->appointments ? $own : null,
                ];
            });
        }

        return response()->json(['tenant_id' => $tenant, 'tenant_slug' => $model?->slug, ...$config, 'tickets' => null])
            ->header('Cache-Control', 'no-store');
    }
}
