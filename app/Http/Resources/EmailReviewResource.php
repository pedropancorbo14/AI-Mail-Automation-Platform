<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una decisión del historial de la revisión humana, con el email al que se
 * refiere (asunto y remitente) para enlazarlo desde el panel.
 *
 * @mixin \App\Models\EmailReview
 */
class EmailReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'decision' => $this->decision->value,
            'decision_label' => $this->decision->label(),
            'edited' => $this->edited,
            'reason' => $this->reason,
            'waited_seconds' => $this->waited_seconds,
            'user' => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name] : null,
            'email' => $this->email ? [
                'id' => $this->email->id,
                'subject' => $this->email->subject,
                'from' => ['email' => $this->email->from_email, 'name' => $this->email->from_name],
                'intent' => $this->email->intent?->value,
                'status' => $this->email->status->value,
            ] : null,
            'at' => $this->created_at?->toIso8601String(),
        ];
    }
}
