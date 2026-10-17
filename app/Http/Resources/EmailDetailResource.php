<?php

namespace App\Http\Resources;

use App\AI\Agent\Enums\EmailReplyStatus;
use App\Automation\Review\HumanReviewService;
use App\Mail\Enums\RecipientType;
use App\Models\Email;
use App\Models\EmailAttachment;
use App\Models\EmailRecipient;
use App\Models\EmailReply;
use App\Models\EmailReview;
use App\Models\EmailStatusChange;
use Illuminate\Http\Request;

/**
 * Detalle de un email en el panel: lo del listado + destinatarios, cuerpo
 * en texto, clasificación completa, adjuntos, historial de estados,
 * respuestas (propuestas o escritas por personas), decisiones de la
 * revisión humana y traza del agente. El HTML va aparte y saneado
 * (GET /emails/{id}/html). Sin tokens ni costes de IA: son datos del
 * operador de la plataforma (Grafana), no de los clientes.
 *
 * @mixin \App\Models\Email
 */
class EmailDetailResource extends EmailListResource
{
    /** Carga todo lo que muestra el detalle (en una consulta por relación). */
    public static function for(Email $email): self
    {
        $person = fn (string $relation) => "{$relation}:id,name";

        $email->load([
            'mailConnection:id,name,email',
            'recipients',
            'attachments',
            'classificationRequest:id,model',
            $person('assignee'),
            $person('statusChanges.user'),
            $person('replies.approvedBy'),
            $person('replies.author'),
            $person('replies.editedBy'),
            $person('replies.rejectedBy'),
            $person('reviews.user'),
            'agentRuns.aiRequests' => fn ($q) => $q->orderBy('id'),
            'agentRuns.aiRequests.toolCalls' => fn ($q) => $q->orderBy('id'),
        ])->loadCount('attachments');

        return new self($email);
    }

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
            // Revisión humana: si espera a una persona, a quién está asignado y lo que decidieron.
            'awaiting_person' => in_array($this->status, HumanReviewService::REVIEWABLE, true),
            // Ya se intentó enviar una respuesta (puede haber llegado): no se reprocesa con la IA.
            'send_attempted' => $this->replies->contains(fn (EmailReply $reply) => in_array($reply->status, [EmailReplyStatus::Sending, EmailReplyStatus::Sent, EmailReplyStatus::Failed], true)),
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'reviews' => $this->reviews->map(fn (EmailReview $review) => [
                'id' => $review->id,
                'decision' => $review->decision->value,
                'decision_label' => $review->decision->label(),
                'edited' => $review->edited,
                'reason' => $review->reason,
                'waited_seconds' => $review->waited_seconds,
                'reply_id' => $review->email_reply_id,
                'user' => $review->user ? ['id' => $review->user->id, 'name' => $review->user->name] : null,
                'at' => $review->created_at?->toIso8601String(),
            ])->all(),
            'agent_runs' => AgentRunResource::collection($this->agentRuns),
        ];
    }
}
