<?php

namespace App\Documents;

use App\Documents\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Tenant;
use App\Tenancy\TenantService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * Indexa un documento (extraer → trocear → embeddings → guardar trozos).
 *
 * Va por la conexión ai.rag.queue_connection (redis-long, retry_after
 * 1900 s): indexar en CPU puede tardar minutos y con retry_after corto Redis
 * relanzaría el job mientras sigue en marcha. Recibe ids (entra en el tenant
 * con runAs). Único por documento.
 */
class ProcessDocumentJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;

    /** < retry_after (1900) de redis-long. */
    public int $timeout = 1800;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $documentId,
    ) {
        $this->onConnection(config('ai.rag.queue_connection'));
        $this->onQueue('documents');
    }

    public function uniqueId(): string
    {
        return (string) $this->documentId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(TenantService $tenants, DocumentService $documents): void
    {
        $tenant = Tenant::find($this->tenantId);

        if ($tenant === null || ! $tenant->is_active) {
            return;
        }

        $tenants->runAs($tenant, function () use ($documents) {
            Context::add('tenant_id', $this->tenantId);

            $document = Document::find($this->documentId);

            if ($document !== null) {
                $documents->process($document);   // errores temporales se relanzan → reintento
            }
        });
    }

    /** Agotados los reintentos (p. ej. Ollama caído): el documento queda como fallido. */
    public function failed(?Throwable $e): void
    {
        $tenant = Tenant::find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        app(TenantService::class)->runAs($tenant, function () use ($e) {
            $document = Document::find($this->documentId);

            if ($document !== null && $document->status !== DocumentStatus::Ready) {
                $document->markFailed($e?->getMessage() ?? 'No se pudo indexar el documento.');
            }
        });
    }
}
