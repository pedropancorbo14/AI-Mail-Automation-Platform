<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\CustomerOrder;
use App\Models\Tenant;
use App\Tenancy\TenantService;
use Illuminate\Http\JsonResponse;

/**
 * GET /internal/integrations/{tenant}: qué sistemas tiene conectados una
 * empresa, para que el servidor MCP elija el proveedor de cada herramienta.
 * `null` = no tiene ese sistema (el servidor MCP usa entonces los datos de
 * demostración si la empresa los tiene, o nada).
 *
 * De momento: pedidos importados por CSV, servidos por la API estándar de
 * Laravel (/internal/standard-api/{tenant}). Una empresa desactivada no
 * tiene nada.
 */
class IntegrationsController extends Controller
{
    public function __invoke(int $tenant, TenantService $tenants): JsonResponse
    {
        $model = Tenant::find($tenant);
        $hasCsvOrders = $model !== null && $model->is_active
            && $tenants->runAs($model, fn () => CustomerOrder::query()->exists());

        return response()->json([
            'tenant_id' => $tenant,
            'orders' => $hasCsvOrders ? ['type' => 'csv'] : null,
            'invoices' => null,
            'tickets' => null,
        ]);
    }
}
