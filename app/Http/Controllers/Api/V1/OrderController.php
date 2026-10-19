<?php

namespace App\Http\Controllers\Api\V1;

use App\ERP\Csv\OrderCsvImporter;
use App\ERP\Csv\OrderImportException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerOrderResource;
use App\Models\CustomerOrder;
use App\Models\Permission;
use App\Security\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Pedidos que la empresa actual importa por CSV (Fase 14). La IA los consulta
 * por el servidor MCP, que los lee con la API estándar interna.
 */
class OrderController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize(Permission::ORDERS_VIEW);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:254'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $query = CustomerOrder::query()->orderByRaw('placed_at desc nulls last')->orderByDesc('id');
        if (($q = trim((string) ($validated['q'] ?? ''))) !== '') {
            $like = '%' . addcslashes(mb_strtolower($q), '%_\\') . '%';
            $query->where(fn ($w) => $w->whereRaw('lower(number) like ?', [$like])
                ->orWhere('customer_email', 'like', $like)
                ->orWhereRaw('lower(customer_name) like ?', [$like]));
        }

        return CustomerOrderResource::collection($query->paginate($validated['per_page'] ?? 25)->withQueryString())
            ->additional(['meta' => [
                'total_orders' => CustomerOrder::count(),
                'last_imported_at' => CustomerOrder::max('updated_at'),
            ]]);
    }

    /**
     * Plantilla para rellenar en Excel: separador ";" y BOM para que Excel en
     * español la abra bien (un test comprueba que se importa tal cual).
     */
    public function template(): BinaryFileResponse
    {
        Gate::authorize(Permission::ORDERS_MANAGE);

        return response()->download(resource_path('templates/pedidos-plantilla.csv'), 'pedidos-plantilla.csv', [
            'Content-Type' => 'text/csv; charset=utf-8',
        ]);
    }

    /** Importa un CSV (todo o nada; actualiza por número de pedido). */
    public function import(Request $request, OrderCsvImporter $importer): JsonResponse
    {
        Gate::authorize(Permission::ORDERS_MANAGE);

        $request->validate([
            'file' => ['required', 'file', 'max:' . config('integrations.csv.max_upload_mb') * 1024],
        ]);
        $file = $request->file('file');

        try {
            $result = $importer->import((string) $file->get());
        } catch (OrderImportException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['file' => [$e->getMessage()]],
                'rows' => $e->rows,
                'total_row_errors' => $e->totalRowErrors,
            ], 422);
        }

        // Sin datos de los clientes: solo el fichero y los recuentos.
        $this->audit->record('orders.imported', metadata: [
            'filename' => $file->getClientOriginalName(),
            ...$result->toArray(),
        ]);

        return response()->json(['data' => $result->toArray()]);
    }

    /** Borra todos los pedidos importados de la empresa. */
    public function destroyAll(): JsonResponse
    {
        Gate::authorize(Permission::ORDERS_MANAGE);

        $deleted = CustomerOrder::query()->delete();
        $this->audit->record('orders.deleted', metadata: ['orders' => $deleted]);

        return response()->json(['data' => ['deleted' => $deleted]]);
    }
}
