<?php

namespace App\AI\Remote;

use App\AI\Remote\Contracts\EventStream;
use App\Models\Email;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * Publica email.review_resumed.v1: una persona aprobó o rechazó en el panel
 * la acción que ai-service dejó pendiente. ai-service reanuda el grafo en
 * pausa (thread_id) y publica un nuevo email.processed.v1.
 *
 * Como EmailEventPublisher: primero se guarda que se ha pedido
 * (ai_event_id, ai_requested_at) y se publica tras el commit; si Redis
 * falla, el reconciliador vuelve a publicar la decisión (guardada en
 * ai_pending_action). Repetirla no duplica la acción: ai-service la ejecuta
 * una sola vez (action_id = clave de idempotencia).
 */
class ReviewResumedPublisher
{
    public const EVENT = 'email.review_resumed';

    public const VERSION = 1;

    public function __construct(
        private readonly EventStream $streams,
        private readonly EventContract $contract,
    ) {
    }

    public static function stream(): string
    {
        return config('ai.remote.stream_prefix') . self::EVENT . '.v' . self::VERSION;
    }

    /**
     * Publica la decisión guardada en ai_pending_action (approved, decided_by).
     *
     * @return array<string, mixed> El evento publicado.
     */
    public function publish(Email $email): array
    {
        $action = $email->ai_pending_action;

        if (! is_array($action) || ! array_key_exists('approved', $action)) {
            throw new LogicException('El email no tiene una acción decidida que publicar.');
        }

        $event = $this->contract->validate('email.review_resumed.v1', [
            'event_id' => (string) Str::uuid(),
            'event_type' => self::EVENT,
            'event_version' => self::VERSION,
            'occurred_at' => now()->toIso8601ZuluString('microsecond'),
            'trace_id' => EmailEventPublisher::traceId(),
            'tenant_id' => $email->tenant_id,
            'email_id' => $email->id,
            'thread_id' => $action['thread_id'],
            'action_id' => $action['action_id'],
            'approved' => (bool) $action['approved'],
            'reviewer_id' => $action['decided_by'] ?? null,
        ]);

        $email->forceFill([
            'ai_event_id' => $event['event_id'],
            'ai_requested_at' => now(),
            'ai_request_attempts' => $email->ai_request_attempts + 1,
        ])->save();

        DB::afterCommit(function () use ($event, $email) {
            try {
                $this->streams->publish(self::stream(), [
                    'event_id' => $event['event_id'],
                    'event_type' => self::EVENT,
                    'payload' => (string) json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
                Log::info('Decisión de la persona enviada al servicio de IA', ['email_id' => $email->id, 'event_id' => $event['event_id']]);
            } catch (Throwable $e) {
                Log::warning('No se pudo publicar la decisión para el servicio de IA', [
                    'email_id' => $email->id,
                    'exception' => $e::class,
                    'detail' => $e->getMessage(),
                ]);
            }
        });

        return $event;
    }

    /**
     * Guarda la decisión de la persona en la acción pendiente (sin
     * publicarla). Es una petición nueva al servicio: los intentos del
     * reconciliador vuelven a empezar.
     */
    public static function decide(Email $email, bool $approved, User $by): void
    {
        $email->forceFill(['ai_request_attempts' => 0, 'ai_pending_action' => [
            ...$email->ai_pending_action,
            'approved' => $approved,
            'decided_by' => $by->id,
            'decided_at' => now()->toIso8601ZuluString(),
        ]])->save();
    }
}
