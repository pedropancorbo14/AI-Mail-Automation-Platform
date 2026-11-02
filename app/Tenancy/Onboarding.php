<?php

namespace App\Tenancy;

use App\Documents\Enums\DocumentStatus;
use App\Mail\Enums\EmailStatus;
use App\Mail\Enums\MailConnectionStatus;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Email;
use App\Models\MailConnection;
use App\Models\Tenant;

/**
 * Primeros pasos de una empresa nueva (Fase 17), calculados con lo que ya
 * hay en la base de datos: no hay que marcarlos a mano. Se ejecuta dentro
 * del tenant.
 */
class Onboarding
{
    public function __construct(private readonly TenantService $tenancy)
    {
    }

    /** @return list<array{key: string, label: string, description: string, done: bool, route: string}> */
    public function steps(Tenant $tenant): array
    {
        return $this->tenancy->runAs($tenant, fn () => [
            [
                'key' => 'mailbox',
                'label' => 'Conecta el buzón de atención al cliente',
                'description' => 'Microsoft 365, Gmail o cualquier correo con IMAP. Los correos nuevos llegarán solos.',
                'done' => MailConnection::where('status', MailConnectionStatus::Active)->exists(),
                'route' => 'mail-connections',
            ],
            [
                'key' => 'documents',
                'label' => 'Sube tu documentación',
                'description' => 'Políticas, preguntas frecuentes, condiciones: la IA responde solo con lo que diga.',
                'done' => Document::where('status', DocumentStatus::Ready)->exists(),
                'route' => 'documents',
            ],
            [
                'key' => 'ai_settings',
                'label' => 'Revisa los ajustes de la IA',
                'description' => 'Al empezar todo va a revisión de tu equipo. Cuando confíes, deja que responda sola a algunos tipos de email.',
                'done' => AuditLog::whereIn('action', ['automation.updated', 'automation.tool_changed'])->exists(),
                'route' => 'ai-settings',
            ],
            [
                'key' => 'team',
                'label' => 'Invita a tu equipo',
                'description' => 'Quien revisa y responde los emails que la IA no envía sola.',
                'done' => $tenant->users()->count() > 1,
                'route' => 'company',
            ],
            [
                'key' => 'first_email',
                'label' => 'Recibe el primer email',
                'description' => 'Escribe al buzón conectado y mira en "Revisión" lo que propone la IA.',
                'done' => Email::whereNotIn('status', [EmailStatus::Received, EmailStatus::Processing])->exists(),
                'route' => 'review',
            ],
        ]);
    }
}
