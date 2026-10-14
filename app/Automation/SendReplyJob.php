<?php

namespace App\Automation;

use App\AI\Agent\Enums\EmailReplyStatus;
use App\Models\EmailReply;
use App\Models\Tenant;
use App\Tenancy\TenantService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * Envía una respuesta aprobada (cola "mail", la de los servidores de
 * correo). Recibe ids y entra en el tenant. Solo se reintentan los errores
 * de conexión (el servidor no aceptó el correo); si se agotan los
 * intentos, la respuesta queda fallida y el email en Failed.
 */
class SendReplyJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $replyId,
    ) {
        $this->onQueue('mail');
    }

    public function uniqueId(): string
    {
        return (string) $this->replyId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(TenantService $tenants, ReplySender $sender): void
    {
        $this->inTenant($tenants, function () use ($sender) {
            if ($reply = EmailReply::find($this->replyId)) {
                $sender->send($reply);
            }
        });
    }

    public function failed(?Throwable $e): void
    {
        $this->inTenant(app(TenantService::class), function () use ($e) {
            $reply = EmailReply::find($this->replyId);

            // Solo si se quedó esperando un reintento (los demás fallos ya están registrados).
            if ($reply?->status === EmailReplyStatus::Approved) {
                app(ReplySender::class)->fail($reply, $e?->getMessage() ?? 'Se agotaron los intentos de envío.');
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
