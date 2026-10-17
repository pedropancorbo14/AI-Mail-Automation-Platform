<?php

namespace App\Automation;

use App\AI\AIService;
use App\AI\Agent\AgentService;
use App\AI\Agent\Enums\EmailReplyStatus;
use App\AI\Classification\EmailClassifier;
use App\AI\Classification\EmailIntent;
use App\AI\Exceptions\LLMConfigurationException;
use App\AI\Exceptions\LLMException;
use App\AI\Remote\AIEngine;
use App\AI\Remote\EmailEventPublisher;
use App\AI\Remote\Exceptions\InvalidEventException;
use App\Mail\Enums\EmailStatus;
use App\Models\Email;
use App\Models\EmailReply;
use App\Security\AiSpendGuard;
use App\Tenancy\TenantService;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * Procesa un email de principio a fin (sección 17, MVP):
 *
 *   received → processing → [automático] → ignored
 *                         → clasificación → [spam / respuesta automática] → ignored
 *                         → agente → ai_review → reglas → approved → (SendReplyJob) → sent
 *                                                       ↘ human_review
 *
 * Con AI_ENGINE=python, tras los filtros baratos (automático, modo off,
 * freno del gasto) el email se publica para ai-service (email.received.v1)
 * y se queda en processing; el resultado lo aplica RemoteResultHandler.
 *
 * Cada paso queda en el historial de estados con su motivo. Solo se gasta
 * IA cuando sirve: los correos automáticos, las empresas sin IA o que han
 * agotado su presupuesto diario, los remitentes que inundan el buzón
 * (AiSpendGuard) y los emails con sospecha de prompt injection no llegan
 * al agente. Se ejecuta
 * dentro del tenant del email.
 */
class EmailProcessor
{
    public function __construct(
        private readonly EmailClassifier $classifier,
        private readonly AgentService $agent,
        private readonly AutoSendPolicy $policy,
        private readonly ReplySender $sender,
        private readonly TenantService $tenants,
        private readonly AiSpendGuard $spendGuard,
        private readonly EmailEventPublisher $publisher,
    ) {
    }

    /**
     * @param  bool  $reprocess  Volver a procesar (a mano) un email ya decidido
     *                           (human_review, ignored o failed): se repite el agente.
     * @return EmailStatus|null Estado final, o null si el email no estaba para procesar.
     *
     * @throws LLMException reintentable (429, sobrecarga, red): el email sigue en
     *                      processing y el job lo reintenta.
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
        } catch (LLMException $e) {
            if ($e->retryable) {
                throw $e;
            }

            // Clave mala, sin saldo, modelo inexistente...: hay que arreglarlo y reprocesar.
            // Un rechazo o una respuesta inválida del clasificador: lo atiende una persona.
            $e instanceof LLMConfigurationException
                ? $email->transitionTo(EmailStatus::Failed, "La IA no está bien configurada: {$e->getMessage()}")
                : $email->transitionTo(EmailStatus::HumanReview, "No se pudo clasificar con la IA: {$e->getMessage()}");
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

        $remote = AIEngine::current()->isRemote();

        // Con el motor remoto la clave de Anthropic la tiene ai-service, no Laravel.
        if (! $remote && ! AIService::enabled()) {
            $email->transitionTo(EmailStatus::HumanReview, 'La IA no está configurada (falta ANTHROPIC_API_KEY): lo atiende una persona.');

            return;
        }

        if ($reason = $this->spendGuard->blockReason($email)) {
            $email->transitionTo(EmailStatus::HumanReview, $reason);

            return;
        }

        if ($remote) {
            $this->publishForRemote($email, $reprocess);

            return;
        }

        if ($email->classified_at === null) {
            $email->recordClassification($this->classifier->classify($email));
        }

        if ($reason = self::ignoreReason($email)) {
            $email->transitionTo(EmailStatus::Ignored, $reason);

            return;
        }

        if ($email->injection_suspected) {
            $email->transitionTo(EmailStatus::HumanReview, 'Posible intento de manipular a la IA: lo revisa una persona, sin propuesta de respuesta.');

            return;
        }

        $reply = $this->reply($email, $reprocess);

        if ($reply === null) {
            $error = $email->agentRuns()->value('error') ?? 'motivo desconocido';
            $email->transitionTo(EmailStatus::HumanReview, "La IA no pudo preparar una respuesta ({$error}): lo atiende una persona.");

            return;
        }

        $email->transitionTo(EmailStatus::AiReview, sprintf('Respuesta propuesta por la IA (confianza %.2f).', $reply->confidence));

        $decision = $this->policy->check($email, $reply);

        if (! $decision->allowed()) {
            $email->transitionTo(EmailStatus::HumanReview, $decision->reason());

            return;
        }

        $this->sender->approve($reply, sprintf(
            'Envío automático: respuesta segura a «%s» (confianza %.2f).',
            $email->intent->value,
            $reply->confidence,
        ));

        // approve() cambia el estado en su propia instancia (y con cola sync ya se ha enviado).
        $email->refresh();
    }

    /**
     * Se publica para ai-service y el email se queda en processing. Un
     * reintento del job no lo vuelve a publicar mientras la petición anterior
     * siga dentro de plazo (el reconciliador se ocupa de las perdidas).
     */
    private function publishForRemote(Email $email, bool $reprocess): void
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

    /**
     * La propuesta del agente. Si un intento anterior ya la dejó preparada
     * (p. ej. el job se reintentó después), se reutiliza: no se paga dos veces.
     */
    private function reply(Email $email, bool $reprocess): ?EmailReply
    {
        if (! $reprocess && $draft = $email->replies()->where('status', EmailReplyStatus::Draft)->first()) {
            return $draft;
        }

        return $this->agent->run($email)->reply;
    }
}
