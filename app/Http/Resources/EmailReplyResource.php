<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Respuesta a un email: propuesta por el agente o escrita por una persona,
 * con su edición, aprobación, descarte y envío.
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
            // Escrita por una persona (null = la IA).
            'author' => $this->person('author'),
            // Texto que propuso la IA, si una persona lo cambió antes de aprobarlo.
            'original_body' => $this->original_body,
            'edited_by' => $this->person('editedBy'),
            'edited_at' => $this->edited_at?->toIso8601String(),
            'language' => $this->language,
            'confidence' => $this->confidence === null ? null : (float) $this->confidence,
            'needs_human' => $this->needs_human,
            'needs_human_reason' => $this->needs_human_reason,
            'sources' => $this->sources ?? [],
            'pending_actions' => $this->pending_actions ?? [],
            'internal_note' => $this->internal_note,
            'approved_by' => $this->person('approvedBy'),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'sent_automatically' => $this->sent_automatically,
            'sent_to' => $this->sent_to,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'send_error' => $this->send_error,
            'rejected_by' => $this->person('rejectedBy'),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** {id, name} de la persona de la relación (si está cargada), o null. */
    private function person(string $relation): mixed
    {
        return $this->whenLoaded($relation, fn () => $this->{$relation} ? ['id' => $this->{$relation}->id, 'name' => $this->{$relation}->name] : null);
    }
}
