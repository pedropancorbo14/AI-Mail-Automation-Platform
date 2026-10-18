<?php

namespace App\AI\Remote;

use App\AI\Remote\Contracts\EventStream;
use App\Mail\Support\HtmlToText;
use App\Models\Email;
use App\Tools\TenantToolService;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Publica email.received.v1 para que ai-service procese el email.
 *
 * El email se queda en processing hasta que llega el resultado
 * (App\AI\Remote\RemoteResultHandler). Primero se guarda que se ha pedido
 * (ai_event_id, ai_requested_at) y después se publica, tras el commit: si
 * Redis falla al publicar, no se pierde el email; el reconciliador
 * (ai:reconcile-remote) lo vuelve a publicar pasado el plazo.
 */
class EmailEventPublisher
{
    public const EVENT = 'email.received';

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
     * @return array<string, mixed> El evento publicado.
     *
     * @throws Exceptions\InvalidEventException si el email no cabe en el contrato (p. ej. sin remitente).
     */
    public function publish(Email $email): array
    {
        $event = $this->contract->validate('email.received.v1', $this->build($email));

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
                Log::info('Email enviado al servicio de IA', ['email_id' => $email->id, 'event_id' => $event['event_id']]);
            } catch (Throwable $e) {
                // Sigue en processing con ai_requested_at: el reconciliador lo reintentará.
                Log::warning('No se pudo publicar el email para el servicio de IA', [
                    'email_id' => $email->id,
                    'exception' => $e::class,
                    'detail' => $e->getMessage(),
                ]);
            }
        });

        return $event;
    }

    /**
     * El evento de un email. En modo sombra (copia para comparar con el motor de
     * Laravel) no lleva la indicación de una persona: esa es del email real.
     *
     * @return array<string, mixed>
     */
    public function build(Email $email, string $mode = 'live'): array
    {
        $email->loadMissing('attachments', 'tenant');

        $body = $email->text_body ?: ($email->html_body ? HtmlToText::convert($email->html_body) : '');
        $limit = (int) config('ai.classification.max_body_chars');
        $truncated = mb_strlen($body) > $limit;

        return [
            'event_id' => (string) Str::uuid(),
            'event_type' => self::EVENT,
            'event_version' => self::VERSION,
            'occurred_at' => now()->toIso8601ZuluString('microsecond'),
            'trace_id' => self::traceId(),
            'tenant_id' => $email->tenant_id,
            'email_id' => $email->id,
            'from' => (string) $email->from_email,
            'from_name' => $email->from_name ? mb_substr($email->from_name, 0, 255) : null,
            'subject' => mb_substr((string) $email->subject, 0, 998),
            'body' => $truncated ? mb_substr($body, 0, $limit) : $body,
            'body_truncated' => $truncated,
            'received_at' => $email->created_at->toIso8601ZuluString('microsecond'),
            'attachment_names' => $email->attachments->take(50)
                ->map(fn ($attachment) => mb_substr((string) $attachment->filename, 0, 255))->values()->all(),
            'tenant_name' => mb_substr($email->tenant->name, 0, 255),
            // Indicación de la persona al reprocesar: la respuesta la revisa siempre una persona.
            'review_instruction' => $mode === 'live' && $email->ai_review_instruction ? mb_substr($email->ai_review_instruction, 0, 2000) : null,
            'mode' => $mode,
            // La empresa decide qué herramientas usa la IA; ai-service solo usa estas.
            'enabled_tools' => app(TenantToolService::class)->enabledNames($email->tenant),
        ];
    }

    /** El request_id de la petición o del job que lo encoló; si no hay, uno nuevo. */
    public static function traceId(): string
    {
        $requestId = Context::get('request_id');

        return is_string($requestId) && preg_match('/^[A-Za-z0-9._-]{8,64}$/', $requestId)
            ? $requestId
            : Str::replace('-', '', (string) Str::uuid());
    }
}
