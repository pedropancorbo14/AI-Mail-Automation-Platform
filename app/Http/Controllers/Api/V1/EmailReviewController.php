<?php

namespace App\Http\Controllers\Api\V1;

use App\Automation\Review\HumanReviewService;
use App\Automation\Review\ReviewException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Emails\ApproveReplyRequest;
use App\Http\Requests\Emails\AssignEmailRequest;
use App\Http\Requests\Emails\CheckReplyRequest;
use App\Http\Requests\Emails\DecideActionRequest;
use App\Http\Requests\Emails\RejectReplyRequest;
use App\Http\Requests\Emails\ReprocessEmailRequest;
use App\Http\Requests\Emails\WriteReplyRequest;
use App\Http\Resources\EmailDetailResource;
use App\Models\Email;
use App\Models\EmailReply;
use App\Security\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;

/**
 * Revisión humana de un email (Fase 13): aprobar (o editar y aprobar) la
 * respuesta de la IA, descartarla, responder, cerrar sin responder,
 * reprocesar con la IA (con una indicación, si se usa ai-service), aprobar
 * o rechazar la acción que propuso ai-service y asignarse el caso. La lógica está en
 * HumanReviewService; aquí, permiso, auditoría y respuesta.
 *
 * Cada acción devuelve el detalle actualizado del email. Si otra persona o
 * la plataforma se adelantó → 409 con el motivo. En la auditoría no se
 * guarda el texto de las respuestas ni los motivos (pueden llevar datos del
 * cliente y la auditoría dura más que el email): solo qué se decidió.
 */
class EmailReviewController extends Controller
{
    public function __construct(private readonly HumanReviewService $review, private readonly AuditLogger $audit)
    {
    }

    public function approve(ApproveReplyRequest $request, Email $email, EmailReply $reply): EmailDetailResource
    {
        $reply = $this->attempt(fn () => $this->review->approve($reply, $request->user(), $request->validated('body')));

        $this->audit->record('email.reply_approved', $email, self::label($email), metadata: ['reply_id' => $reply->id, 'edited' => $reply->wasEdited()]);

        return EmailDetailResource::for($email->refresh());
    }

    public function reject(RejectReplyRequest $request, Email $email, EmailReply $reply): EmailDetailResource
    {
        $this->attempt(fn () => $this->review->reject($reply, $request->user(), $request->validated('reason')));

        $this->audit->record('email.reply_rejected', $email, self::label($email), metadata: ['reply_id' => $reply->id]);

        return EmailDetailResource::for($email->refresh());
    }

    public function reply(WriteReplyRequest $request, Email $email): EmailDetailResource
    {
        $reply = $this->attempt(fn () => $this->review->reply($email, $request->user(), $request->validated('body')));

        $this->audit->record('email.replied', $email, self::label($email), metadata: ['reply_id' => $reply->id]);

        return EmailDetailResource::for($email->refresh());
    }

    public function close(RejectReplyRequest $request, Email $email): EmailDetailResource
    {
        $this->attempt(fn () => $this->review->close($email, $request->user(), $request->validated('reason')));

        $this->audit->record('email.closed', $email, self::label($email));

        return EmailDetailResource::for($email->refresh());
    }

    public function reprocess(ReprocessEmailRequest $request, Email $email): EmailDetailResource
    {
        $instruction = $request->validated('instruction');
        $this->attempt(fn () => $this->review->reprocess($email, $request->user(), $instruction));

        // Sin el texto de la indicación: puede llevar datos del cliente.
        $this->audit->record('email.reprocessed', $email, self::label($email), metadata: [
            'with_instruction' => is_string($instruction) && trim($instruction) !== '',
        ]);

        return EmailDetailResource::for($email->refresh());
    }

    public function approveAction(DecideActionRequest $request, Email $email): EmailDetailResource
    {
        return $this->decideAction($request, $email, true);
    }

    public function rejectAction(DecideActionRequest $request, Email $email): EmailDetailResource
    {
        return $this->decideAction($request, $email, false);
    }

    public function assign(AssignEmailRequest $request, Email $email): EmailDetailResource
    {
        $previous = $email->assigned_to;
        $assignee = $request->assignee();

        $this->review->assign($email, $assignee);

        if ($previous !== $assignee?->id) {
            $this->audit->record('email.assigned', $email, self::label($email), metadata: [
                'from_user_id' => $previous,
                'to_user_id' => $assignee?->id,
                'to_user' => $assignee?->name,
            ]);
        }

        return EmailDetailResource::for($email->refresh());
    }

    /**
     * Comprueba un texto antes de enviarlo: avisos de las comprobaciones
     * automáticas (no impiden enviar; decide la persona).
     */
    public function check(CheckReplyRequest $request, Email $email): JsonResponse
    {
        return response()->json(['data' => [
            'warnings' => $this->review->warnings($email, $request->validated('body'), $request->basedOn()),
        ]]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $decision
     * @return T
     */
    private function attempt(callable $decision): mixed
    {
        try {
            return $decision();
        } catch (ReviewException $e) {
            abort(409, $e->getMessage());
        }
    }

    private function decideAction(DecideActionRequest $request, Email $email, bool $approved): EmailDetailResource
    {
        $tool = $email->undecidedAction()['tool'] ?? null;
        $this->attempt(fn () => $this->review->decideAction($email, $request->user(), $approved));

        // Solo qué acción y qué se decidió (sus argumentos llevan datos del cliente).
        $this->audit->record($approved ? 'email.action_approved' : 'email.action_rejected', $email, self::label($email), metadata: [
            'tool' => $tool,
        ]);

        return EmailDetailResource::for($email->refresh());
    }

    private static function label(Email $email): string
    {
        return "Email #{$email->id}";
    }
}
