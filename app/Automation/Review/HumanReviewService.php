<?php

namespace App\Automation\Review;

use App\AI\Agent\Enums\EmailReplyStatus;
use App\Automation\EmailProcessor;
use App\Automation\ProcessEmailJob;
use App\Automation\ReplySender;
use App\Mail\Enums\EmailStatus;
use App\Mail\Exceptions\EmailStatusException;
use App\Models\Email;
use App\Models\EmailReply;
use App\Models\EmailReview;
use App\Models\User;
use App\Security\ReplyContentGuard;
use App\Tenancy\TenantService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Decisiones de una persona sobre un email que espera revisión humana
 * (sección 12): aprobar la respuesta de la IA (tal cual o editada),
 * descartarla, escribir una propia, cerrar el caso sin responder o
 * reprocesarlo con la IA. Se ejecuta dentro del tenant del email.
 *
 * Cada decisión queda en email_reviews (quién, qué, si editó el texto, cuánto
 * esperó el caso) y en el historial de estados con la persona. Los cambios
 * son condicionales (como en ReplySender): si otra persona o la plataforma
 * se adelantó, se lanza ReviewException y no se cambia nada.
 *
 * Un email "espera a una persona" en human_review o en failed (un envío
 * fallido o un error de procesamiento también los resuelve una persona).
 */
class HumanReviewService
{
    /** Estados desde los que una persona puede decidir. */
    public const array REVIEWABLE = [EmailStatus::HumanReview, EmailStatus::Failed];

    public function __construct(
        private readonly ReplySender $sender,
        private readonly TenantService $tenants,
        private readonly ReplyContentGuard $guard,
    ) {
    }

    /**
     * Aprueba la respuesta propuesta y la envía. Con $body, la persona la ha
     * editado: se guarda el texto original de la IA para poder compararlo.
     */
    public function approve(EmailReply $reply, User $by, ?string $body = null): EmailReply
    {
        $email = $this->reviewableEmail($reply->email()->firstOrFail());
        $this->ensureDraft($reply);

        $edited = $body !== null && self::normalize($body) !== self::normalize($reply->body);

        $this->decide(function () use ($email, $reply, $by, $body, $edited) {
            $waited = $this->waitedSeconds($email);

            if ($edited) {
                $reply->forceFill([
                    'original_body' => $reply->original_body ?? $reply->body,
                    'body' => self::normalize($body),
                    'edited_by' => $by->id,
                    'edited_at' => now(),
                ])->save();
            }

            $this->discardOtherDrafts($email, $reply, $by, 'Se aprobó otra respuesta.');
            $this->toHumanReview($email, $by);
            $this->record($email, $by, ReviewDecision::Approved, $reply, edited: $edited, waited: $waited);

            $this->sender->approve($reply, ($edited ? 'Respuesta de la IA aprobada con cambios por ' : 'Respuesta de la IA aprobada por ') . $by->name, $by);
        });

        return $reply->refresh();
    }

    /**
     * Descarta la propuesta de la IA. El email sigue esperando a una persona
     * (que puede escribir su respuesta, cerrarlo o reprocesarlo).
     */
    public function reject(EmailReply $reply, User $by, ?string $reason = null): EmailReply
    {
        $email = $this->reviewableEmail($reply->email()->firstOrFail());
        $this->ensureDraft($reply);

        $this->decide(function () use ($email, $reply, $by, $reason) {
            if (! $this->markRejected($reply, $by, $reason)) {
                throw ReviewException::replyNotDraft();
            }

            $this->record($email, $by, ReviewDecision::Rejected, $reply, reason: $reason, waited: $this->waitedSeconds($email));
        });

        return $reply->refresh();
    }

    /** La persona escribe su propia respuesta (sin propuesta de la IA, o en lugar de ella) y se envía. */
    public function reply(Email $email, User $by, string $body): EmailReply
    {
        $email = $this->reviewableEmail($email);

        $reply = $this->decide(function () use ($email, $by, $body) {
            $waited = $this->waitedSeconds($email);

            $this->discardOtherDrafts($email, null, $by, "Sustituida por una respuesta escrita por {$by->name}.");
            $this->toHumanReview($email, $by);

            $reply = $email->replies()->make([
                'body' => self::normalize($body),
                'needs_human' => false,
                'sources' => [],
                'pending_actions' => [],
            ]);
            $reply->forceFill(['created_by' => $by->id])->save();

            $this->record($email, $by, ReviewDecision::Replied, $reply, waited: $waited);
            $this->sender->approve($reply, "Respuesta escrita por {$by->name}", $by);

            return $reply;
        });

        // El envío se encola al confirmar la transacción.
        return $reply->refresh();
    }

    /** Cierra el caso sin responder (no necesita respuesta, ya se atendió por otra vía...). */
    public function close(Email $email, User $by, ?string $reason = null): void
    {
        $email = $this->reviewableEmail($email);

        $this->decide(function () use ($email, $by, $reason) {
            $waited = $this->waitedSeconds($email);

            $this->discardOtherDrafts($email, null, $by, 'El caso se cerró sin responder.');
            $email->transitionTo(EmailStatus::Ignored, "Cerrado sin responder por {$by->name}" . ($reason ? ": {$reason}" : '.'), $by);
            $this->record($email, $by, ReviewDecision::Closed, reason: $reason, waited: $waited);
        });
    }

    /**
     * Vuelve a procesar el email con la IA (clasificación ya hecha + agente
     * de nuevo). Las propuestas anteriores se descartan. Nunca si ya se
     * intentó enviar una respuesta: podría llegar dos veces.
     */
    public function reprocess(Email $email, User $by): void
    {
        if (! in_array($email->status, [...self::REVIEWABLE, EmailStatus::Ignored], true)) {
            throw ReviewException::notReprocessable();
        }

        if (EmailProcessor::sendWasAttempted($email)) {
            throw ReviewException::sendAttempted();
        }

        $this->decide(function () use ($email, $by) {
            $waited = $this->waitedSeconds($email);

            $this->discardOtherDrafts($email, null, $by, 'Sustituida al reprocesar el email con la IA.');
            // El job sigue desde processing (como un reintento) y repite el agente.
            $email->transitionTo(EmailStatus::Processing, "Reprocesado con la IA por {$by->name}.", $by);
            $this->record($email, $by, ReviewDecision::Reprocessed, waited: $waited);

            ProcessEmailJob::dispatch($email->tenant_id, $email->id, reprocess: true)->afterCommit();
        });
    }

    /** Se asigna el caso a una persona (o se libera con null). No cambia el estado del email. */
    public function assign(Email $email, ?User $to): Email
    {
        $email->forceFill([
            'assigned_to' => $to?->id,
            'assigned_at' => $to ? now() : null,
        ])->save();

        return $email;
    }

    /**
     * Avisos sobre un texto antes de enviarlo: las comprobaciones de
     * ReplyContentGuard (enlaces y direcciones desconocidos, pedidos o
     * importes que no salen de ningún dato, detalles internos), contra los
     * datos que consultó la IA para $basedOn. No impiden enviar: decide la
     * persona.
     *
     * @return list<string>
     */
    public function warnings(Email $email, string $body, ?EmailReply $basedOn = null): array
    {
        $probe = new EmailReply(['body' => self::normalize($body), 'agent_run_id' => $basedOn?->agent_run_id]);

        return $this->guard->problems($email, $probe, automatic: false);
    }

    /** Quita los saltos de línea de Windows y los espacios del final: no cuentan como edición. */
    public static function normalize(string $body): string
    {
        return rtrim(str_replace("\r\n", "\n", $body));
    }

    private function reviewableEmail(Email $email): Email
    {
        if ($this->tenants->id() !== $email->tenant_id) {
            throw new LogicException('La revisión debe ejecutarse dentro del tenant del email.');
        }

        $email->refresh();

        if (! in_array($email->status, self::REVIEWABLE, true)) {
            throw ReviewException::notWaitingForAPerson();
        }

        return $email;
    }

    private function ensureDraft(EmailReply $reply): void
    {
        if ($reply->refresh()->status !== EmailReplyStatus::Draft) {
            throw ReviewException::replyNotDraft();
        }
    }

    /**
     * Ejecuta la decisión en una transacción. Si el email cambió de estado
     * entre medias (otra persona, la plataforma), no se aplica nada.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function decide(callable $callback): mixed
    {
        try {
            return DB::transaction($callback);
        } catch (EmailStatusException) {
            throw ReviewException::notWaitingForAPerson();
        } catch (LogicException $e) {
            // ReplySender::approve(): la respuesta dejó de ser un borrador.
            if (str_contains($e->getMessage(), 'ya no es un borrador')) {
                throw ReviewException::replyNotDraft();
            }

            throw $e;
        }
    }

    /** Un email con error pasa primero a revisión humana (failed → approved no es un paso válido). */
    private function toHumanReview(Email $email, User $by): void
    {
        if ($email->status === EmailStatus::Failed) {
            $email->transitionTo(EmailStatus::HumanReview, "Lo revisa {$by->name}.", $by);
        }
    }

    private function discardOtherDrafts(Email $email, ?EmailReply $keep, User $by, string $reason): void
    {
        $email->replies()
            ->where('status', EmailReplyStatus::Draft)
            ->when($keep, fn ($q) => $q->whereKeyNot($keep->id))
            ->get()
            ->each(fn (EmailReply $draft) => $this->markRejected($draft, $by, $reason));
    }

    private function markRejected(EmailReply $reply, User $by, ?string $reason): bool
    {
        $updated = EmailReply::query()
            ->whereKey($reply->id)
            ->where('status', EmailReplyStatus::Draft)
            ->update([
                'status' => EmailReplyStatus::Rejected,
                'rejected_by' => $by->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
                'updated_at' => now(),
            ]) === 1;

        if ($updated) {
            // El observer de métricas y los modelos ya cargados ven el cambio.
            $reply->refresh();
        }

        return $updated;
    }

    /** Segundos desde que el email entró en su estado actual (cuánto esperó a una persona). */
    private function waitedSeconds(Email $email): ?int
    {
        $since = $email->statusChanges()->reorder('id', 'desc')->value('created_at');

        return $since === null ? null : max(0, (int) Carbon::parse($since)->diffInSeconds(now()));
    }

    private function record(Email $email, User $by, ReviewDecision $decision, ?EmailReply $reply = null, bool $edited = false, ?string $reason = null, ?int $waited = null): EmailReview
    {
        return $email->reviews()->create([
            'email_reply_id' => $reply?->id,
            'user_id' => $by->id,
            'decision' => $decision,
            'edited' => $edited,
            'reason' => $reason,
            'waited_seconds' => $waited,
        ]);
    }
}
