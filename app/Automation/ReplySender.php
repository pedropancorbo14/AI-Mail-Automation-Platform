<?php

namespace App\Automation;

use App\AI\Agent\Enums\EmailReplyStatus;
use App\Mail\Data\MailAddress;
use App\Mail\Data\OutgoingMessage;
use App\Mail\Enums\EmailStatus;
use App\Mail\Enums\MailConnectionStatus;
use App\Mail\Enums\RecipientType;
use App\Mail\Exceptions\MailAuthenticationException;
use App\Mail\Exceptions\MailConnectionException;
use App\Mail\Exceptions\MailProviderException;
use App\Mail\MailProviderFactory;
use App\Models\Email;
use App\Models\EmailRecipient;
use App\Models\EmailReply;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * Aprueba una respuesta y la envía en el hilo del email original, por el
 * buzón en el que llegó. Se ejecuta dentro del tenant del email.
 *
 * Nunca envía dos veces: antes de enviar "reclama" la respuesta con un
 * cambio de estado condicional (approved → sending), y solo se reintenta
 * cuando el servidor no llegó a aceptar el correo (error de conexión). Si
 * el resultado es desconocido, queda fallida para que la revise una persona.
 */
class ReplySender
{
    /** Message-IDs del hilo que se conservan en References (los más recientes). */
    private const int MAX_REFERENCES = 20;

    public function __construct(private readonly MailProviderFactory $providers)
    {
    }

    /**
     * Aprueba la propuesta (borrador) y encola su envío.
     *
     * @param  User|null  $by  Quién la aprueba; null = las reglas de envío automático.
     */
    public function approve(EmailReply $reply, string $reason, ?User $by = null): void
    {
        $email = $reply->email()->firstOrFail();

        DB::transaction(function () use ($reply, $email, $reason, $by) {
            $updated = EmailReply::query()
                ->whereKey($reply->id)
                ->where('status', EmailReplyStatus::Draft)
                ->update([
                    'status' => EmailReplyStatus::Approved,
                    'approved_by' => $by?->id,
                    'approved_at' => now(),
                    'sent_automatically' => $by === null,
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                throw new LogicException("La respuesta {$reply->id} ya no es un borrador.");
            }

            $email->transitionTo(EmailStatus::Approved, $reason, $by);
        });

        $reply->refresh();

        SendReplyJob::dispatch($reply->tenant_id, $reply->id)->afterCommit();
    }

    /**
     * Envía una respuesta aprobada.
     *
     * @throws MailConnectionException el servidor no respondió: la respuesta vuelve a
     *         "approved" y el job la reintenta.
     */
    public function send(EmailReply $reply): void
    {
        $email = $reply->email()->with('mailConnection')->firstOrFail();

        if ($email->status !== EmailStatus::Approved || ! $this->claim($reply)) {
            // Ya enviada, en envío por otro worker, o el email cambió de estado.
            return;
        }

        $connection = $email->mailConnection;

        try {
            if ($connection->status === MailConnectionStatus::Disabled) {
                throw new MailProviderException('El buzón está desactivado: no se puede enviar la respuesta.');
            }

            $message = $this->message($email, $reply);
            $sent = $this->providers->make($connection)->send($message);
        } catch (MailConnectionException $e) {
            $this->release($reply);

            throw $e;
        } catch (MailProviderException $e) {
            if ($e instanceof MailAuthenticationException && $connection->status !== MailConnectionStatus::Disabled) {
                $connection->forceFill(['status' => MailConnectionStatus::Failing, 'last_error' => $e->getMessage()])->save();
            }

            $this->fail($reply, $e->getMessage());

            return;
        } catch (Throwable $e) {
            // No se sabe si salió: no se reintenta (podría llegar dos veces).
            Log::error('Error inesperado al enviar una respuesta', ['email_reply_id' => $reply->id, 'exception' => $e::class, 'detail' => $e->getMessage()]);
            $this->fail($reply, 'Error interno al enviar la respuesta: revisa si llegó antes de reenviarla.');

            throw $e;
        }

        $to = $message->to[0]->email;

        DB::transaction(function () use ($reply, $email, $to, $sent) {
            $reply->forceFill([
                'status' => EmailReplyStatus::Sent,
                'sent_to' => $to,
                'sent_message_id' => $sent->messageId,
                'sent_at' => now(),
                'send_error' => null,
            ])->save();

            $email->transitionTo(EmailStatus::Sent, ($reply->sent_automatically ? 'Respuesta enviada automáticamente a ' : 'Respuesta enviada a ') . $to);
        });

        Log::info('Respuesta enviada', ['email_id' => $email->id, 'email_reply_id' => $reply->id, 'automatic' => $reply->sent_automatically]);
    }

    /** Deja la respuesta fallida y el email en Failed con el motivo (no se reintenta). */
    public function fail(EmailReply $reply, string $error): void
    {
        DB::transaction(function () use ($reply, $error) {
            $reply->forceFill(['status' => EmailReplyStatus::Failed, 'send_error' => $error])->save();

            $email = $reply->email()->firstOrFail();

            if ($email->status->canTransitionTo(EmailStatus::Failed)) {
                $email->transitionTo(EmailStatus::Failed, "No se pudo enviar la respuesta: {$error}");
            }
        });

        Log::warning('Respuesta no enviada', ['email_reply_id' => $reply->id, 'error' => $error]);
    }

    /**
     * El correo a enviar: al "Responder a" del original (o a su remitente),
     * con "Re:" y las cabeceras de hilo para que se agrupe con la
     * conversación.
     */
    public function message(Email $email, EmailReply $reply): OutgoingMessage
    {
        $references = array_values(array_unique(array_filter([...$email->references ?? [], $email->message_id])));

        return new OutgoingMessage(
            to: $this->recipients($email),
            subject: self::subject($email->subject),
            textBody: $reply->body,
            inReplyTo: $email->message_id,
            references: array_slice($references, -self::MAX_REFERENCES),
            autoSubmitted: $reply->sent_automatically,
        );
    }

    /** @return list<MailAddress> */
    private function recipients(Email $email): array
    {
        $replyTo = $email->recipients()
            ->where('type', RecipientType::ReplyTo)
            ->get()
            ->map(fn (EmailRecipient $recipient) => new MailAddress($recipient->email, $recipient->name))
            ->all();

        if ($replyTo !== []) {
            return $replyTo;
        }

        if (! $email->from_email) {
            throw new MailProviderException('El email no tiene remitente al que responder.');
        }

        return [new MailAddress($email->from_email, $email->from_name)];
    }

    private static function subject(?string $subject): string
    {
        $subject = trim((string) $subject);

        if ($subject === '') {
            return 'Re: (sin asunto)';
        }

        // "Re:", "RE:", "Aw:" (alemán), "Sv:" (nórdico), "Antw:" (neerlandés), "R:" (italiano).
        return preg_match('/^(re|aw|sv|antw|r)\s*:/iu', $subject) ? $subject : "Re: {$subject}";
    }

    private function claim(EmailReply $reply): bool
    {
        $claimed = EmailReply::query()
            ->whereKey($reply->id)
            ->where('status', EmailReplyStatus::Approved)
            ->update(['status' => EmailReplyStatus::Sending, 'updated_at' => now()]) === 1;

        $reply->refresh();

        return $claimed;
    }

    private function release(EmailReply $reply): void
    {
        $reply->forceFill(['status' => EmailReplyStatus::Approved])->save();
    }
}
