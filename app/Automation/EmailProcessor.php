<?php

namespace App\Automation;

use App\AI\Agent\Enums\EmailReplyStatus;
use App\AI\Classification\EmailIntent;
use App\AI\Remote\EmailEventPublisher;
use App\AI\Remote\Exceptions\InvalidEventException;
use App\Mail\Enums\EmailStatus;
use App\Models\Email;
use App\Security\AiSpendGuard;
use App\Tenancy\TenantService;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * Procesa un email de principio a fin (sección 17, MVP). Laravel aplica los
 * filtros baratos y la IA la hace ai-service:
 *
 *   received → processing → [automático] → ignored
 *                         → [modo off / freno del gasto] → human_review
 *                         → email.received.v1 → (ai-service) → RemoteResultHandler
 *                              → ignored | human_review | ai_review → reglas → approved → sent
 *
 * Tras publicar, el email se queda en processing hasta que llega el resultado
 * (o el reconciliador lo republica o lo pasa a una persona). Cada paso queda en
 * el historial de estados con su motivo. Solo se gasta IA cuando sirve: los
 * correos automáticos, las empresas sin IA o que han agotado su presupuesto
 * diario y los remitentes que inundan el buzón (AiSpendGuard) no llegan a
 * ai-service. Se ejecuta dentro del tenant del email.
 */
class EmailProcessor
{
    public function __construct(
        private readonly TenantService $tenants,
        private readonly AiSpendGuard $spendGuard,
        private readonly EmailEventPublisher $publisher,
    ) {
    }

    /**
     * @param  bool  $reprocess  Volver a procesar (a mano) un email ya decidido
     *                           (human_review, ignored o failed): se vuelve a publicar.
     * @return EmailStatus|null Estado en que queda (processing = esperando a
     *                          ai-service), o null si el email no estaba para procesar.
     */
    public function process(Email $email, bool $reprocess = false): ?EmailStatus
    {
        if ($this->tenants->id() !== $email->tenant_id) {
            throw new LogicException('El procesamiento debe ejecutarse dentro del tenant del email.');
        }

        if (! $this->start($email, $reprocess)) {
            return null;
        }

        try {
            $this->run($email, $reprocess);
        } catch (Throwable $e) {
            Log::error('Error inesperado al procesar un email', ['email_id' => $email->id, 'exception' => $e::class, 'detail' => $e->getMessage()]);

            if ($email->status === EmailStatus::Processing) {
                $email->transitionTo(EmailStatus::Failed, 'Error interno al procesar el email.');
            }

            throw $e;
        }

        Log::info($email->status === EmailStatus::Processing ? 'Email esperando al servicio de IA' : 'Email procesado', [
            'email_id' => $email->id,
            'status' => $email->status->value,
        ]);

        return $email->status;
    }

    private function start(Email $email, bool $reprocess): bool
    {
        return match (true) {
            $email->status === EmailStatus::Received => tap(true, fn () => $email->transitionTo(EmailStatus::Processing, 'Procesamiento automático.')),
            // Reintento del job: sigue donde estaba.
            $email->status === EmailStatus::Processing => true,
            $reprocess && $email->status->canTransitionTo(EmailStatus::Processing) && ! self::sendWasAttempted($email)
                => tap(true, fn () => $email->transitionTo(EmailStatus::Processing, 'Reprocesado a mano.')),
            default => false,
        };
    }

    /**
     * Si ya se intentó enviar una respuesta, no se reprocesa: podría llegar
     * dos veces al cliente (un envío fallido puede haber salido). Lo
     * resuelve una persona.
     */
    public static function sendWasAttempted(Email $email): bool
    {
        return $email->replies()
            ->whereIn('status', [EmailReplyStatus::Sending, EmailReplyStatus::Sent, EmailReplyStatus::Failed])
            ->exists();
    }

    private function run(Email $email, bool $reprocess): void
    {
        if ($email->isAutomated()) {
            $email->transitionTo(EmailStatus::Ignored, "Correo automático: {$email->automated_reason}");

            return;
        }

        if (! $email->tenant->automation_mode->usesAI()) {
            $email->transitionTo(EmailStatus::HumanReview, 'La automatización está desactivada en la empresa: lo atiende una persona.');

            return;
        }

        if ($reason = $this->spendGuard->blockReason($email)) {
            $email->transitionTo(EmailStatus::HumanReview, $reason);

            return;
        }

        $this->publish($email, $reprocess);
    }

    /**
     * Se publica para ai-service y el email se queda en processing. Un
     * reintento del job no lo vuelve a publicar mientras la petición anterior
     * siga dentro de plazo (el reconciliador se ocupa de las perdidas).
     */
    private function publish(Email $email, bool $reprocess): void
    {
        $window = now()->subMinutes((int) config('ai.remote.reconcile_after_minutes'));

        if (! $reprocess && $email->ai_requested_at?->gt($window)) {
            return;
        }

        try {
            $this->publisher->publish($email);
        } catch (InvalidEventException $e) {
            // P. ej. sin remitente válido: la IA no puede procesarlo.
            $email->transitionTo(EmailStatus::HumanReview, "No se pudo enviar al servicio de IA ({$e->getMessage()}): lo atiende una persona.");
        }
    }

    /**
     * Spam o respuesta automática con confianza alta: no necesita respuesta.
     * Pública: también la aplica RemoteResultHandler a los resultados de ai-service.
     */
    public static function ignoreReason(Email $email): ?string
    {
        $confidence = (float) $email->intent_confidence;

        if (! in_array($email->intent, [EmailIntent::Spam, EmailIntent::AutoReply], true)
            || $confidence < (float) config('ai.automation.ignore_min_confidence')) {
            return null;
        }

        return sprintf('Clasificado como %s (confianza %.2f): no necesita respuesta.', $email->intent->value, $confidence);
    }
}
