<?php

namespace App\Console\Commands;

use App\Documents\DocumentService;
use App\Documents\Exceptions\DocumentProcessingException;
use App\Models\Tenant;
use App\Tenancy\TenantService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Sube un documento a la base de conocimiento de una empresa desde la consola. */
#[Signature('kb:import
    {path : Ruta del fichero (dentro del contenedor)}
    {--tenant= : Id de la empresa}
    {--title= : Título (por defecto, el nombre del fichero)}')]
#[Description('Sube un documento (PDF, TXT, MD, HTML) a la base de conocimiento de una empresa')]
class KnowledgeImport extends Command
{
    public function handle(TenantService $tenants, DocumentService $documents): int
    {
        $tenant = Tenant::find($this->option('tenant'));
        $path = (string) $this->argument('path');

        if ($tenant === null) {
            $this->error('Indica una empresa existente con --tenant=<id>.');

            return self::FAILURE;
        }

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("No se puede leer el fichero {$path}.");

            return self::FAILURE;
        }

        try {
            $document = $tenants->runAs($tenant, fn () => $documents->upload(
                (string) file_get_contents($path),
                basename($path),
                $this->option('title'),
                detectedMime: mime_content_type($path) ?: null,
            )->fresh());
        } catch (DocumentProcessingException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Documento {$document->id} «{$document->title}» subido ({$document->status->value}).");

        return self::SUCCESS;
    }
}
