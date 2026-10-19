<?php

namespace App\Http\Resources;

use App\Models\TenantIntegration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Conexión con el sistema de la empresa, sin la clave. @mixin TenantIntegration */
class TenantIntegrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->type,
            'base_url' => $this->base_url,
            'has_token' => filled($this->credentials),
            'orders' => $this->orders,
            'invoices' => $this->invoices,
            'enabled' => $this->enabled,
            'status' => $this->status,
            'last_tested_at' => $this->last_tested_at,
            'last_error' => $this->last_error,
            'updated_at' => $this->updated_at,
        ];
    }
}
