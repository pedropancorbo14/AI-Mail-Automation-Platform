<?php

namespace App\Security\Retention;

use App\Models\CustomerOrder;
use App\Models\Email;
use App\Models\EmailRecipient;
use App\Models\MailSkippedMessage;
use App\Models\Tenant;
use App\Security\Audit\AuditLogger;
use App\Tenancy\TenantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Políticas de retención y derecho de supresión. Cada borrado queda en la
 * auditoría de la empresa (sin datos del cliente: del email olvidado solo
 * se guarda su hash).
 */
class RetentionService
{
    public function __construct(
        private readonly EmailEraser $eraser,
        private readonly TenantService $tenants,
        private readonly AuditLogger $audit,
    ) {
    }

    /** Días que la empresa conserva sus emails (0 = sin límite). */
    public static function emailDays(Tenant $tenant): int
    {
        return (int) ($tenant->email_retention_days ?? config('retention.emails_days'));
    }

    /** Borra los emails más antiguos que la retención de la empresa. Devuelve cuántos. */
    public function pruneEmails(Tenant $tenant): int
    {
        if (($days = self::emailDays($tenant)) <= 0) {
            return 0;
        }

        $erased = $this->tenants->runAs($tenant, function () use ($days) {
            $count = 0;

            Email::where('created_at', '<', now()->subDays($days))->chunkById(100, function ($emails) use (&$count) {
                foreach ($emails as $email) {
                    if (! EmailEraser::isBusy($email)) {
                        $this->eraser->erase($email);
                        $count++;
                    }
                }
            });

            // Correos apartados por la sincronización: tienen remitente y asunto.
            MailSkippedMessage::where('created_at', '<', now()->subDays($days))->delete();

            return $count;
        });

        if ($erased > 0) {
            $this->audit->record('retention.emails_purged', metadata: ['emails' => $erased, 'days' => $days], tenantId: $tenant->id);
            Log::info('Retención: emails antiguos borrados', ['tenant_id' => $tenant->id, 'emails' => $erased, 'days' => $days]);
        }

        return $erased;
    }

    /** Borra la auditoría más antigua que retention.audit_days (todas las empresas). */
    public function pruneAuditLogs(): int
    {
        if (($days = (int) config('retention.audit_days')) <= 0) {
            return 0;
        }

        // Consulta directa: el modelo no deja borrar y el trigger solo bloquea UPDATE.
        return DB::table('audit_logs')->where('created_at', '<', now()->subDays($days))->delete();
    }

    /**
     * Derecho de supresión: borra todos los emails de una dirección (como
     * remitente o como destinatario) en la empresa. Devuelve cuántos.
     */
    public function forgetAddress(Tenant $tenant, string $address, bool $dryRun = false): int
    {
        $address = mb_strtolower(trim($address));
        $orders = 0;

        $count = $this->tenants->runAs($tenant, function () use ($address, $dryRun, &$orders) {
            // Pedidos que la empresa importó por CSV (Fase 14): también son suyos.
            if (! $dryRun) {
                $orders = CustomerOrder::where('customer_email', $address)->delete();
                MailSkippedMessage::whereRaw('lower(from_email) = ?', [$address])->delete();
            }

            $ids = Email::whereRaw('lower(from_email) = ?', [$address])->pluck('id')
                ->merge(EmailRecipient::whereRaw('lower(email) = ?', [$address])->pluck('email_id'))
                ->unique();

            if ($dryRun) {
                return $ids->count();
            }

            $erased = 0;

            foreach (Email::whereKey($ids)->get() as $email) {
                // Uno en pleno procesamiento o envío se deja: se puede repetir en unos minutos.
                if (EmailEraser::isBusy($email)) {
                    continue;
                }

                $this->eraser->erase($email);
                $erased++;
            }

            return $erased;
        });

        if (! $dryRun) {
            $this->audit->record('retention.address_forgotten', metadata: ['address_sha256' => hash('sha256', $address), 'emails' => $count, 'orders' => $orders], tenantId: $tenant->id);
        }

        return $count;
    }

    /** Pedidos importados de una dirección en la empresa (para avisar antes de borrar). */
    public function ordersOf(Tenant $tenant, string $address): int
    {
        $address = mb_strtolower(trim($address));

        return $this->tenants->runAs($tenant, fn () => CustomerOrder::where('customer_email', $address)->count());
    }
}
