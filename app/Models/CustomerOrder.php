<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Pedido importado por CSV (App\ERP\Csv\OrderCsvImporter). Nada es asignable
 * en masa: lo escribe solo el importador. Se sirve al servidor MCP con la API
 * estándar (App\Http\Controllers\Internal\StandardApiController).
 */
class CustomerOrder extends Model
{
    use BelongsToTenant;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'placed_at' => 'datetime',
            'shipped_at' => 'datetime',
            'estimated_delivery' => 'datetime',
            'delivered_at' => 'datetime',
            'total' => 'decimal:2',
            'items' => 'array',
        ];
    }

    /** El pedido en el formato de la API estándar (order.v1). */
    public function toStandardApi(): array
    {
        $shipment = array_filter([
            'carrier' => $this->carrier,
            'tracking_number' => $this->tracking_number,
            'tracking_url' => $this->tracking_url,
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'estimated_delivery' => $this->estimated_delivery?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
        ], fn ($value) => $value !== null);

        return [
            'number' => $this->number,
            'customer_email' => $this->customer_email,
            'status' => $this->status,
            'placed_at' => $this->placed_at?->toIso8601String(),
            'customer_name' => $this->customer_name,
            'total' => $this->total === null ? null : (float) $this->total,
            'currency' => $this->currency,
            'items' => $this->items ?? [],
            'shipment' => $shipment === [] ? null : $shipment,
            'shipping_city' => $this->shipping_city,
            'shipping_country' => $this->shipping_country,
        ];
    }
}
