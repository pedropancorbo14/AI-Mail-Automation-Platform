<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Un caso de la bandeja de revisión: lo del listado + desde cuándo espera,
 * por qué (motivo del último cambio de estado) y si hay propuesta de la IA.
 *
 * @mixin \App\Models\Email
 */
class ReviewQueueItemResource extends EmailListResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'waiting_since' => $this->waiting_since ? Carbon::parse($this->waiting_since)->toIso8601String() : null,
            'waiting_reason' => $this->waiting_reason,
            'has_draft' => (bool) $this->has_draft,
        ];
    }
}
