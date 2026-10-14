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

/** JSON de clasificación tal como lo devolvería el modelo (sobrescribible por campo). */
function classificationData(array $overrides = []): array
{
    return array_replace_recursive([
        'intent' => 'order_status',
        'confidence' => 0.92,
        'language' => 'es',
        'summary' => 'El cliente pregunta dónde está su pedido #45821.',
        'needs_human' => false,
        'needs_human_reason' => null,
        'sentiment' => 'neutral',
        'urgency' => 'normal',
        'injection_suspected' => false,
        'entities' => ['order_numbers' => ['45821'], 'invoice_numbers' => [], 'customer_name' => 'Cliente Pérez'],
    ], $overrides);
}

/** Activa herramientas de la IA para una empresa (las que no son "por defecto" vienen desactivadas). */
function enableTools(Tenant $tenant, string ...$tools): void
{
    foreach ($tools as $tool) {
        app(App\Tools\TenantToolService::class)->enable($tenant, $tool);
    }
}

/** JSON de respuesta final del agente tal como lo devolvería el modelo (sobrescribible por campo). */
function agentReplyData(array $overrides = []): array
{
    return array_replace([
        'reply_body' => "Hola:\n\nTu pedido #45821 salió el 29 de septiembre con SEUR (seguimiento SEU123456789ES).\n\nUn saludo,\nEl equipo de atención al cliente",
        'reply_language' => 'es',
        'confidence' => 0.9,
        'needs_human' => false,
        'needs_human_reason' => null,
        'sources' => [['type' => 'order', 'reference' => '45821']],
        'pending_actions' => [],
        'internal_note' => null,
    ], $overrides);
}
