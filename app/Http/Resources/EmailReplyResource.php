<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Respuesta propuesta por el agente (y su aprobación/envío).
 *
 * @mixin \App\Models\EmailReply
 */
class EmailReplyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agent_run_id' => $this->agent_run_id,
            'status' => $this->status->value,
            'body' => $this->body,
            'language' => $this->language,
            'confidence' => $this->confidence === null ? null : (float) $this->confidence,
            'needs_human' => $this->needs_human,
            'needs_human_reason' => $this->needs_human_reason,
            'sources' => $this->sources ?? [],
            'pending_actions' => $this->pending_actions ?? [],
            'internal_note' => $this->internal_note,
            'approved_by' => $this->whenLoaded('approvedBy', fn () => $this->approvedBy ? ['id' => $this->approvedBy->id, 'name' => $this->approvedBy->name] : null),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'sent_automatically' => $this->sent_automatically,
            'sent_to' => $this->sent_to,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'send_error' => $this->send_error,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
