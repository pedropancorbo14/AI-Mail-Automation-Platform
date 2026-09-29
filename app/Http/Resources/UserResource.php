<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->whenLoaded('role', fn () => $this->role->name),
            // Solo en /auth/me (carga role.permissions): para que el panel
            // muestre u oculte acciones. La autorización real va en backend.
            'permissions' => $this->when(
                $this->relationLoaded('role') && $this->role->relationLoaded('permissions'),
                fn () => $this->role->permissions->pluck('name')->sort()->values()->all(),
            ),
            'tenant' => $this->whenLoaded('tenant', fn () => [
                'id' => $this->tenant->id,
                'name' => $this->tenant->name,
                'slug' => $this->tenant->slug,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
