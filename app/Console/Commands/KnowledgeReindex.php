<?php

namespace App\Console\Commands;

use App\Documents\DocumentService;
use App\Models\Document;
use App\Tenancy\Scopes\TenantScope;
use App\Tenancy\TenantService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Vuelve a indexar documentos (p. ej. al pasar el indexado a ai-service o al
 * cambiar el modelo de embeddings). Consulta global deliberada: cada documento
 * se reindexa dentro de su empresa.
 */
#[Signature('kb:reindex
    {--tenant= : Solo los documentos de esta empresa (id)}
    {--all : Todos los documentos de todas las empresas}')]
#[Description('Vuelve a indexar los documentos de la base de conocimiento')]
class KnowledgeReindex extends Command
{
    public function handle(DocumentService $documents, TenantService $tenants): int
    {
        if (! $this->option('all') && ! $this->option('tenant')) {
            $this->error('Indica --tenant=<id> o --all.');

            return self::FAILURE;
        }

        $count = 0;
        Document::withoutGlobalScope(TenantScope::class)
            ->with('tenant')
            ->when($this->option('tenant'), fn ($query, $tenant) => $query->where('tenant_id', (int) $tenant))
            ->chunkById(100, function ($batch) use ($documents, $tenants, &$count) {
                foreach ($batch as $document) {
                    $tenants->runAs($document->tenant, fn () => $documents->reindex($document));
                    $count++;
                }
            });

        $this->info("{$count} documentos enviados a reindexar.");

        return self::SUCCESS;
    }
}
