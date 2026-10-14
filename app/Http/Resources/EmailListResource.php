<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Email en el listado del panel: lo justo para la tabla (sin cuerpos).
 *
 * @mixin \App\Models\Email
 */
class EmailListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'from' => ['email' => $this->from_email, 'name' => $this->from_name],
            'mailbox' => $this->whenLoaded('mailConnection', fn () => [
                'id' => $this->mailConnection->id,
                'name' => $this->mailConnection->name,
                'email' => $this->mailConnection->email,
            ]),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'intent' => $this->intent?->value,
            'intent_confidence' => $this->intent_confidence === null ? null : (float) $this->intent_confidence,
            'summary' => $this->summary,
            'language' => $this->language,
            'urgency' => $this->urgency,
            'sentiment' => $this->sentiment,
            'injection_suspected' => (bool) $this->injection_suspected,
            'automated_reason' => $this->automated_reason,
            'attachments_count' => $this->whenCounted('attachments'),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'received_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
