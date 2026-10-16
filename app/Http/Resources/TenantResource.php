<?php

namespace App\Http\Resources;

use App\Security\Retention\RetentionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Tenant */
class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'is_active' => $this->is_active,
            // Retención de emails: la propia (null = la de la plataforma) y la que se aplica.
            'email_retention_days' => $this->email_retention_days,
            'retention' => [
                'email_days' => RetentionService::emailDays($this->resource),
                'platform_email_days' => (int) config('retention.emails_days'),
                'min_email_days' => (int) config('retention.min_email_days'),
                'max_email_days' => (int) config('retention.max_email_days'),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
