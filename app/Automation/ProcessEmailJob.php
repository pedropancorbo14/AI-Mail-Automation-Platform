<?php

namespace App\Automation;

use App\Mail\Enums\EmailStatus;
use App\Models\Email;
use App\Models\Tenant;
use App\Tenancy\TenantService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Procesa un email con EmailProcessor (cola "ai" en la conexión
 * ai.automation.queue_connection). Recibe ids y entra en el tenant. Único
 * por email. Un error inesperado se reintenta con espera; si se agotan los
 * intentos, el email queda en Failed.
 */
class ProcessEmailJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;

    /** Filtros y publicación para ai-service; < retry_after 300 de "redis". */
    public int $timeout = 120;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $emailId,
        public readonly bool $reprocess = false,
    ) {
        $this->onConnection(config('ai.automation.queue_connection'));
        $this->onQueue('ai');
    }

    public function uniqueId(): string
    {
        return (string) $this->emailId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(TenantService $tenants, EmailProcessor $processor): void
    {
        $this->inTenant($tenants, function () use ($processor) {
            if ($email = Email::find($this->emailId)) {
                $processor->process($email, $this->reprocess);
            }
        });
    }

    public function failed(?Throwable $e): void
    {
        Log::warning('Procesamiento de email abandonado', ['email_id' => $this->emailId, 'error' => $e?->getMessage()]);

        $this->inTenant(app(TenantService::class), function () use ($e) {
            $email = Email::find($this->emailId);

            if ($email?->status === EmailStatus::Processing) {
                $email->transitionTo(EmailStatus::Failed, 'No se pudo procesar tras varios intentos: ' . ($e?->getMessage() ?? 'error desconocido'));
            }
        });
    }

    private function inTenant(TenantService $tenants, callable $callback): void
    {
        $tenant = Tenant::find($this->tenantId);

        if ($tenant === null || ! $tenant->is_active) {
            return;
        }

        $tenants->runAs($tenant, function () use ($callback) {
            Context::add('tenant_id', $this->tenantId);
            $callback();
        });
    }
}
