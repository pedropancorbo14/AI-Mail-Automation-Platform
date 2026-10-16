<?php

namespace App\Automation;

use App\AI\Agent\Enums\EmailReplyStatus;
use App\Mail\Data\MailAddress;
use App\Mail\Exceptions\MailProviderException;
use App\Mail\Support\AutomatedMessageDetector;
use App\Models\Email;
use App\Models\EmailReply;
use App\Models\MailConnection;
use App\Security\ReplyContentGuard;
use Illuminate\Support\Str;

/**
 * Última palabra antes de enviar una respuesta sin revisión humana. Se suma
 * a AgentDecision (que ya marca needs_human): aquí van las reglas de la
 * empresa y las protecciones contra bucles. Basta un motivo para que la
 * respuesta vaya a una persona; se devuelven todos. Dentro del tenant.
 */
class AutoSendPolicy
{
    public function __construct(
        private readonly ReplySender $sender,
        private readonly ReplyContentGuard $contentGuard,
    ) {
    }

    public function check(Email $email, EmailReply $reply): AutoSendDecision
    {
        $tenant = $email->tenant;
        $reasons = [];

        if (! config('ai.automation.auto_send_enabled')) {
            $reasons[] = 'El envío automático está desactivado en la plataforma.';
        }

        if ($tenant->automation_mode !== AutomationMode::Auto) {
            $reasons[] = "La empresa está en modo «{$tenant->automation_mode->label()}»: una persona revisa todas las respuestas.";
        }

        if ($reply->needs_human) {
            $reasons[] = 'Necesita revisión: ' . rtrim($reply->needs_human_reason ?? 'sin motivo indicado', '. ') . '.';
        }

        if ($email->intent === null) {
            $reasons[] = 'El email no está clasificado.';
        } elseif (! in_array($email->intent, $tenant->autoSendIntents(), true)) {
            $reasons[] = "La empresa no permite responder solas a la intención «{$email->intent->value}».";
        }

        if ($email->isAutomated()) {
            $reasons[] = "Es un correo automático: {$email->automated_reason}";
        }

        if ($email->injection_suspected) {
            $reasons[] = 'El email parece intentar dar instrucciones a la IA.';
        }

        array_push($reasons, ...$this->recipientReasons($email));
        array_push($reasons, ...$this->contentGuard->problems($email, $reply));

        return new AutoSendDecision($reasons);
    }

    /** @return list<string> */
    private function recipientReasons(Email $email): array
    {
        try {
            $recipients = $this->sender->recipients($email);
        } catch (MailProviderException) {
            return ['El email no tiene remitente al que responder.'];
        }

        $reasons = [];
        $ownMailboxes = MailConnection::query()->pluck('email')->map(fn (string $address) => Str::lower($address))->all();
        $max = (int) config('ai.automation.max_auto_replies_per_address');

        foreach ($recipients as $recipient) {
            $address = Str::lower($recipient->email);

            if (in_array($address, $ownMailboxes, true)) {
                $reasons[] = "La respuesta iría a un buzón de la propia empresa ({$address}).";
            }

            if (AutomatedMessageDetector::reason([], $address) !== null) {
                $reasons[] = "La dirección {$address} no acepta respuestas.";
            }

            if (($sent = $this->autoRepliesToday($recipient)) >= $max) {
                $reasons[] = "Ya se han enviado {$sent} respuestas automáticas a {$address} en 24 horas (límite contra bucles).";
            }
        }

        return $reasons;
    }

    private function autoRepliesToday(MailAddress $recipient): int
    {
        return EmailReply::query()
            ->where('sent_automatically', true)
            ->where('status', EmailReplyStatus::Sent)
            ->whereRaw('lower(sent_to) = ?', [Str::lower($recipient->email)])
            ->where('sent_at', '>=', now()->subDay())
            ->count();
    }
}
