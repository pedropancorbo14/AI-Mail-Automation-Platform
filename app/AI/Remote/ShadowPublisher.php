<?php

namespace App\AI\Remote;

use App\AI\Remote\Contracts\EventStream;
use App\Models\AiShadowRun;
use App\Models\Email;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Modo sombra (AI-Service, Fase 5): con el motor de Laravel activo, publica
 * una COPIA de una muestra de emails para ai-service (`mode: shadow`). Lo que
 * recomiende nunca se aplica al email: ShadowResultHandler lo guarda en
 * ai_shadow_runs para compararlo con lo que hizo el motor de Laravel.
 *
 * Nunca rompe el procesamiento real: cualquier fallo se registra y el email
 * sigue con el motor de Laravel como si no hubiera sombra. No toca los campos
 * del motor remoto del email (ai_event_id, ai_requested_at...).
 */
class ShadowPublisher
{
    public function __construct(
        private readonly EventStream $streams,
        private readonly EventContract $contract,
        private readonly EmailEventPublisher $events,
    ) {
    }

    /** Hay sombra: activada y con el motor de Laravel (con el de Python no hay nada que comparar). */
    public static function enabled(): bool
    {
        return (bool) config('ai.shadow.enabled') && ! AIEngine::current()->isRemote();
    }

    /** Muestra determinista por el id del email (el mismo email, la misma respuesta). */
    public static function sampled(Email $email): bool
    {
        $rate = max(0.0, min(1.0, (float) config('ai.shadow.sample_rate')));

        return $rate > 0 && (crc32("shadow:{$email->id}") % 10_000) < $rate * 10_000;
    }

    /** Publica la copia si toca. Devuelve la fila creada, o null si no toca o falló. */
    public function maybePublish(Email $email): ?AiShadowRun
    {
        if (! self::enabled() || ! self::sampled($email) || $email->from_email === null) {
            return null;
        }

        try {
            // Una sola copia por email: reprocesarlo no duplica el gasto.
            if (AiShadowRun::where('email_id', $email->id)->exists()) {
                return null;
            }

            $event = $this->contract->validate('email.received.v1', $this->events->build($email, 'shadow'));
            $run = new AiShadowRun();
            $run->forceFill([
                'email_id' => $email->id,
                'event_id' => $event['event_id'],
                'requested_at' => now(),
            ])->save();

            DB::afterCommit(fn () => $this->send($event, $email));

            return $run;
        } catch (Throwable $e) {
            Log::warning('No se pudo publicar la copia en modo sombra (el email sigue normal)', [
                'email_id' => $email->id,
                'exception' => $e::class,
                'detail' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** @param  array<string, mixed>  $event */
    private function send(array $event, Email $email): void
    {
        try {
            $this->streams->publish(EmailEventPublisher::stream(), [
                'event_id' => $event['event_id'],
                'event_type' => EmailEventPublisher::EVENT,
                'payload' => (string) json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable $e) {
            // La copia se queda pendiente: no se reintenta (es solo para comparar).
            Log::warning('No se pudo publicar la copia en modo sombra', [
                'email_id' => $email->id,
                'exception' => $e::class,
            ]);
        }
    }
}
