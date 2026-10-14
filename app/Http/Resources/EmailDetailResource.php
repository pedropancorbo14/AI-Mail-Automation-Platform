<?php

namespace App\Http\Resources;

use App\Mail\Enums\RecipientType;
use App\Models\EmailAttachment;
use App\Models\EmailRecipient;
use App\Models\EmailStatusChange;
use Illuminate\Http\Request;

/**
 * Detalle de un email en el panel: lo del listado + destinatarios, cuerpo
 * en texto, clasificación completa, adjuntos, historial de estados,
 * respuestas propuestas y traza del agente. El HTML va aparte y saneado
 * (GET /emails/{id}/html).
 *
 * @mixin \App\Models\Email
 */
class EmailDetailResource extends EmailListResource
{
    public function toArray(Request $request): array
    {
        $recipients = fn (RecipientType $type) => $this->recipients
            ->filter(fn (EmailRecipient $recipient) => $recipient->type === $type)
            ->map(fn (EmailRecipient $recipient) => ['email' => $recipient->email, 'name' => $recipient->name])
            ->values()
            ->all();

        return [
            ...parent::toArray($request),
            'to' => $recipients(RecipientType::To),
            'cc' => $recipients(RecipientType::Cc),
            'reply_to' => $recipients(RecipientType::ReplyTo),
            'message_id' => $this->message_id,
            'in_reply_to' => $this->in_reply_to,
            'text_body' => $this->text_body,
            'has_html' => $this->html_body !== null && $this->html_body !== '',
            'classification' => $this->classified_at === null ? null : [
                'needs_human' => $this->needs_human,
                'needs_human_reason' => $this->needs_human_reason,
                'entities' => $this->ai_entities ?? [],
                'classified_at' => $this->classified_at->toIso8601String(),
                'model' => $this->classificationRequest?->model,
                'cost_usd' => $this->classificationRequest?->cost_usd === null ? null : (float) $this->classificationRequest->cost_usd,
            ],
            'attachments' => $this->attachments->map(fn (EmailAttachment $attachment) => [
                'id' => $attachment->id,
                'filename' => $attachment->filename,
                'content_type' => $attachment->content_type,
                'size' => $attachment->size,
                'inline' => $attachment->inline,
                'status' => $attachment->status->value,
                'error' => $attachment->error,
            ])->all(),
            'status_history' => $this->statusChanges->map(fn (EmailStatusChange $change) => [
                'from' => $change->from_status?->value,
                'to' => $change->to_status->value,
                'to_label' => $change->to_status->label(),
                'reason' => $change->reason,
                'user' => $change->user ? ['id' => $change->user->id, 'name' => $change->user->name] : null,
                'at' => $change->created_at?->toIso8601String(),
            ])->all(),
            'replies' => EmailReplyResource::collection($this->replies),
            'agent_runs' => AgentRunResource::collection($this->agentRuns),
            // Todo lo que ha costado este email en IA (clasificación + pasos del agente).
            'ai_cost_usd' => round((float) ($this->ai_requests_sum_cost_usd ?? 0), 6),
        ];
    }
}
