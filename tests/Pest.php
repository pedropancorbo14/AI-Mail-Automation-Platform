<?php

use App\Mail\Data\MailAddress;
use App\Mail\Data\MailAttachment;
use App\Mail\Data\MailMessage;
use App\Models\Tenant;
use App\Tenancy\TenantService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class)->in('Feature');

/**
 * Ejecuta $callback dentro de $tenant: necesario para crear o consultar
 * modelos con BelongsToTenant fuera de una petición HTTP.
 *
 * @template T
 *
 * @param  callable(Tenant): T  $callback
 * @return T
 */
function inTenant(Tenant $tenant, callable $callback): mixed
{
    return app(TenantService::class)->runAs($tenant, $callback);
}

/** Mensaje tal como lo devuelve un MailProvider (sobrescribible por campo). */
function incomingMessage(array $overrides = []): MailMessage
{
    return new MailMessage(...array_merge([
        'id' => '42',
        'folder' => 'INBOX',
        'messageId' => 'pedido-45821@cliente.test',
        'subject' => '¿Dónde está mi pedido #45821?',
        'from' => new MailAddress('cliente@cliente.test', 'Cliente Pérez'),
        'to' => [new MailAddress('soporte@empresa.test', 'Soporte'), new MailAddress('ventas@empresa.test')],
        'cc' => [new MailAddress('jefe@cliente.test')],
        'replyTo' => [new MailAddress('respuestas@cliente.test')],
        'date' => CarbonImmutable::parse('2026-09-30 08:15:00', 'UTC'),
        'textBody' => 'Hola, ¿dónde está mi pedido?',
        'htmlBody' => '<p>Hola, ¿dónde está mi <b>pedido</b>?</p>',
        'attachments' => [
            new MailAttachment('1', 'foto.png', 'image/png', 2048, inline: true, contentId: 'foto1'),
            new MailAttachment('2', 'ticket.pdf', 'application/pdf', 4096),
        ],
        'inReplyTo' => 'anterior@empresa.test',
        'references' => ['inicio@cliente.test', 'anterior@empresa.test'],
    ], $overrides));
}

/** Clasificación tal como la guarda Laravel de un resultado de ai-service (sobrescribible por campo). */
function classificationOf(array $overrides = []): App\AI\Classification\EmailClassification
{
    return new App\AI\Classification\EmailClassification(...array_replace([
        'intent' => App\AI\Classification\EmailIntent::OrderStatus,
        'confidence' => 0.92,
        'language' => 'es',
        'summary' => 'El cliente pregunta dónde está su pedido #45821.',
        'needsHuman' => false,
        'needsHumanReason' => null,
        'sentiment' => 'neutral',
        'urgency' => 'normal',
        'injectionSuspected' => false,
        'orderNumbers' => ['45821'],
        'invoiceNumbers' => [],
        'customerName' => 'Cliente Pérez',
    ], $overrides));
}

/** Activa herramientas de la IA para una empresa (las que no son "por defecto" vienen desactivadas). */
function enableTools(Tenant $tenant, string ...$tools): void
{
    foreach ($tools as $tool) {
        app(App\Tools\TenantToolService::class)->enable($tenant, $tool);
    }
}

/**
 * Propuesta de respuesta (borrador) para un email, como la dejaría un
 * resultado de ai-service. Llamar dentro del tenant del email.
 */
function draftReply(App\Models\Email $email, array $overrides = []): App\Models\EmailReply
{
    return $email->replies()->create(array_replace([
        'body' => "Hola:\n\nTu pedido #45821 salió el 29 de septiembre con SEUR.\n\nUn saludo,\nEl equipo de atención al cliente",
        'language' => 'es',
        'confidence' => 0.9,
        'needs_human' => false,
        'sources' => [['type' => 'order', 'reference' => '45821']],
        'pending_actions' => [],
    ], $overrides));
}

/**
 * Valor actual de una serie de /metrics (acumuladas + estado actual; null si
 * no existe; si varias series encajan, su suma).
 * Las etiquetas se dan en el orden de Metrics::DEFINITIONS; las que falten
 * no se comprueban (p. ej. metricSample('x_total', ['tenant' => 'acme'])).
 */
function metricSample(string $name, array $labels = []): ?float
{
    $total = null;

    $text = app(App\Observability\Metrics::class)->render() . app(App\Observability\StateMetrics::class)->render();

    foreach (explode("\n", $text) as $line) {
        if (! preg_match('/^aimail_' . preg_quote($name, '/') . '(?:\{(.*)\})? (\S+)$/', $line, $m)) {
            continue;
        }

        preg_match_all('/(\w+)="([^"]*)"/', $m[1], $pairs, PREG_SET_ORDER);
        $series = array_column($pairs, 2, 1);

        if (array_diff_assoc(array_map(fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : (string) $v, $labels), $series) === []) {
            $total = ($total ?? 0) + (float) $m[2];
        }
    }

    return $total;
}

/**
 * Claves de costes o tokens de IA en una respuesta (a cualquier profundidad;
 * "unit_price" de un pedido es dato del propio cliente y no cuenta).
 * Son datos del operador (Grafana): la API del panel no debe mostrarlos.
 *
 * @return list<string> rutas de las claves encontradas
 */
function costOrTokenKeys(mixed $data, string $path = ''): array
{
    if (! is_array($data)) {
        return [];
    }

    $found = [];

    foreach ($data as $key => $value) {
        $here = ltrim("{$path}.{$key}", '.');

        if (is_string($key) && preg_match('/cost|token|usd/i', $key)) {
            $found[] = $here;
        }

        array_push($found, ...costOrTokenKeys($value, $here));
    }

    return $found;
}

/**
 * Procesa un email con EmailProcessor dentro de su empresa (motor remoto:
 * lo publica para ai-service y lo deja en processing).
 */
function processRemote(App\Models\Email $email, bool $reprocess = false): ?App\Mail\Enums\EmailStatus
{
    return inTenant($email->tenant, fn () => app(App\Automation\EmailProcessor::class)->process($email->fresh(), $reprocess));
}

/** @return list<string> Estados por los que ha pasado un email. */
function remoteStatusPath(App\Models\Email $email): array
{
    return inTenant($email->tenant, fn () => $email->statusChanges()->get()
        ->map(fn (App\Models\EmailStatusChange $change) => $change->to_status->value)->all());
}

/**
 * email.processed.v1 (el ejemplo compartido del contrato) para un email ya
 * publicado: lo que respondería ai-service. Por defecto, una respuesta segura
 * a una pregunta de producto con envío automático recomendado.
 */
function processedEvent(App\Models\Email $email, array $overrides = [], array $classification = []): array
{
    $event = json_decode(file_get_contents(base_path('contracts/events/examples/valid/email.processed.v1.json')), true);
    $event['event_id'] = (string) Illuminate\Support\Str::uuid();
    $event['tenant_id'] = $email->tenant_id;
    $event['email_id'] = $email->id;
    $event['causation_id'] = $email->fresh()->ai_event_id;
    $event['category'] = 'product_question';
    $event['decision'] = 'auto_send';
    $event['confidence'] = 0.93;
    $event['reasons'] = [];
    $event['draft'] = "Hola:\n\nSí, enviamos a Canarias.\n\nUn saludo,\nEl equipo de Empresa Demo";
    $event['classification'] = array_merge($event['classification'], ['summary' => 'Pregunta por envíos a Canarias.', 'order_numbers' => [], 'customer_name' => null], $classification);

    return array_merge($event, $overrides);
}

/** email.processing_failed.v1 (ejemplo del contrato) para un email ya publicado. */
function failedEvent(App\Models\Email $email, array $overrides = []): array
{
    $event = json_decode(file_get_contents(base_path('contracts/events/examples/valid/email.processing_failed.v1.json')), true);

    return array_merge($event, ['event_id' => (string) Illuminate\Support\Str::uuid(), 'tenant_id' => $email->tenant_id, 'email_id' => $email->id, 'causation_id' => null], $overrides);
}

/** Aplica un resultado de ai-service como lo haría ai:consume-results; devuelve el resultado (applied, stale...). */
function applyAiResult(array $event, string $type = 'email.processed'): string
{
    return app(App\AI\Remote\RemoteResultHandler::class)->handle(new App\AI\Remote\Data\StreamMessage(
        "{$type}.v1",
        '1-0',
        ['event_id' => $event['event_id'], 'event_type' => $type, 'payload' => json_encode($event)],
    ));
}

/**
 * Procesa un email de punta a punta con ai-service simulado: Laravel lo
 * publica y se aplica el resultado (processedEvent con $overrides). Devuelve
 * el estado final.
 */
function processWithAi(App\Models\Email $email, array $overrides = [], array $classification = []): App\Mail\Enums\EmailStatus
{
    processRemote($email);
    applyAiResult(processedEvent($email, $overrides, $classification));

    return $email->fresh()->status;
}

function lastStatusReasonOf(App\Models\Email $email): ?string
{
    return inTenant($email->tenant, fn () => $email->statusChanges()->get()->last()?->reason);
}

/** Inserta una fila en emails sin pasar por el modelo (tests de las tablas). */
function insertEmailRow(array $overrides = []): int
{
    if (! isset($overrides['mail_connection_id'])) {
        $tenant = App\Models\Tenant::factory()->create();
        $connection = inTenant($tenant, fn () => App\Models\MailConnection::factory()->create());
        $overrides += ['tenant_id' => $tenant->id, 'mail_connection_id' => $connection->id];
    }

    return Illuminate\Support\Facades\DB::table('emails')->insertGetId(array_merge([
        'folder' => 'INBOX',
        'provider_message_id' => (string) fake()->unique()->randomNumber(),
        'message_id' => fake()->unique()->uuid() . '@cliente.test',
        'dedup_hash' => hash('sha256', fake()->unique()->uuid()),
        'subject' => 'Consulta',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}
