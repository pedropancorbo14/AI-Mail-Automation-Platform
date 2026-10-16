<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una acción del registro de auditoría.
 *
 * @mixin \App\Models\AuditLog
 */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            // Nombre actual si el usuario sigue existiendo; si no, el email que tenía.
            'actor' => $this->user_id === null && $this->actor_email === null ? null : [
                'id' => $this->user_id,
                'name' => $this->user?->name,
                'email' => $this->actor_email,
            ],
            // Sobre qué (puede tener solo nombre, p. ej. una herramienta de la IA).
            'subject' => $this->subject_type === null && $this->subject_label === null ? null : [
                'type' => $this->subject_type,
                'id' => $this->subject_id,
                'label' => $this->subject_label,
            ],
            'changes' => $this->changes,
            'metadata' => $this->metadata,
            'ip' => $this->ip,
            'user_agent' => $this->user_agent,
            'request_id' => $this->request_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
