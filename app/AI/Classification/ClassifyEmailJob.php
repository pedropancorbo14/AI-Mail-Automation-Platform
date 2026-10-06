<?php

namespace App\AI\Classification;

use App\AI\Exceptions\LLMException;
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
 * Clasifica un email con la IA (cola "ai") y guarda el resultado.
 *
 * Recibe ids: al deserializar no hay tenant en contexto y BelongsToTenant
 * fallaría. Único por email (no se paga dos veces la misma clasificación).
 * Solo se reintentan los errores temporales (límite de peticiones,
 * sobrecarga, red); un rechazo, una clave mala o una respuesta inválida
 * fallan sin reintentar: cada intento cuesta dinero y daría lo mismo.
 */
class ClassifyEmailJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 4;

    /** Incluye los reintentos internos del SDK (timeout 60 s × 3); < retry_after 300. */
    public int $timeout = 240;

    public int $uniqueFor = 1800;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $emailId,
        /** Volver a clasificar aunque ya tenga clasificación. */
        public readonly bool $force = false,
    ) {
        $this->onQueue('ai');
    }

    public function uniqueId(): string
    {
        return (string) $this->emailId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(TenantService $tenants, EmailClassifier $classifier): void
    {
        $tenant = Tenant::find($this->tenantId);

        if ($tenant === null || ! $tenant->is_active) {
            return;
        }

        $tenants->runAs($tenant, function () use ($classifier) {
            Context::add('tenant_id', $this->tenantId);

            $email = Email::find($this->emailId);

            // Borrado mientras esperaba, o ya clasificado (p. ej. job repetido).
            if ($email === null || ($email->classified_at !== null && ! $this->force)) {
                return;
            }

            try {
                $email->recordClassification($classifier->classify($email));
            } catch (LLMException $e) {
                // Ya registrado en ai_requests. Temporal → la cola reintenta con backoff().
                if ($e->retryable) {
                    throw $e;
                }

                $this->fail($e);
            }
        });
    }

    public function failed(?Throwable $e): void
    {
        Log::warning('Clasificación de email abandonada', [
            'email_id' => $this->emailId,
            'tenant_id' => $this->tenantId,
            'exception' => $e ? $e::class : null,
            'error' => $e?->getMessage(),
        ]);
    }
}
